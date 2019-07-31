<?php

declare(strict_types=1);

namespace MDClub\Service\Question;

use MDClub\Constant\ApiError;
use MDClub\Exception\ApiException;
use MDClub\Exception\ValidationException;
use MDClub\Helper\Html;
use MDClub\Helper\Markdown;
use MDClub\Helper\Request;
use MDClub\Helper\Validator;

/**
 * 更新提问
 */
class Update extends Abstracts
{
    /**
     * 更新提问
     *
     * @param int    $questionId
     * @param string $title
     * @param string $contentMarkdown
     * @param string $contentRendered
     * @param array  $topicIds         为 null 时表示不更新 topic_id
     */
    public function update(
        int    $questionId,
        string $title = null,
        string $contentMarkdown = null,
        string $contentRendered = null,
        array  $topicIds = null
    ): void {
        [
            $data,
            $topicIds
        ] = $this->updateValidator(
            $questionId,
            $title,
            $contentMarkdown,
            $contentRendered,
            $topicIds
        );

        // 更新文章信息
        if ($data) {
            $this->model
                ->where('question_id', $questionId)
                ->update($data);
        }

        // 更新话题关系
        $this->updateTopicable($questionId, $topicIds, true);
    }

    /**
     * 更新话题关系
     *
     * @param int   $questionId  提问ID
     * @param array $topicIds   话题ID数组
     * @param bool  $checkExist 是否检查已存在的话题关系
     */
    protected function updateTopicable(int $questionId, array $topicIds, bool $checkExist = false): void
    {
        if ($checkExist) {
            $existTopicIds = $this->topicableModel
                ->where('topicable_type', 'question')
                ->where('topicable_id', $questionId)
                ->pluck('topic_id');

            $needDeleteTopicIds = array_diff($existTopicIds, $topicIds);
            if ($needDeleteTopicIds) {
                $this->topicableModel
                    ->where([
                        'topicable_type' => 'question',
                        'topicable_id' => $questionId,
                        'topic_id' => $needDeleteTopicIds,
                    ])->delete();
            }

            $topicIds = array_diff($topicIds, $existTopicIds);
        }

        $topicable = [];
        foreach ($topicIds as $topicId) {
            $topicable[] = [
                'topic_id'       => $topicId,
                'topicable_id'   => $questionId,
                'topicable_type' => 'question',
            ];
        }
        if ($topicable) {
            $this->topicableModel->insert($topicable);
        }
    }

    /**
     * 检查是否具有编辑权限
     *
     * @param int $questionId
     */
    protected function checkEditPermissions(int $questionId): void
    {
        if ($this->roleService->managerId()) {
            return;
        }

        $userId = $this->roleService->userIdOrFail();
        $question = $this->questionGetService->getOrFail($questionId);

        if ($question['user_id'] !== $userId) {
            throw new ApiException(ApiError::QUESTION_CANT_EDIT_NOT_AUTHOR);
        }

        $canEdit = $this->optionService->question_can_edit;
        $canEditBefore = $this->optionService->question_can_edit_before;
        $canEditOnlyNoComment = $this->optionService->question_can_edit_only_no_comment;
        $requestTime = Request::time($this->request);

        if (!$canEdit) {
            throw new ApiException(ApiError::QUESTION_CANT_EDIT);
        }

        if ($canEditBefore && $question['create_time'] + (int) $canEditBefore < $requestTime) {
            throw new ApiException(ApiError::QUESTION_CANT_EDIT_TIMEOUT);
        }

        if ($canEditOnlyNoComment && $question['comment_count']) {
            throw new ApiException(ApiError::QUESTION_CANT_EDIT_HAS_COMMENT);
        }
    }

    /**
     * 过滤不存在的 topic_id
     *
     * @param  array  $topicIds
     * @return array
     */
    protected function filterTopicIds(array $topicIds = null): array
    {
        if ($topicIds === null) {
            return [];
        }

        return $this->topicGetService
            ->fetchCollection()
            ->hasMultiple($topicIds)
            ->filter()
            ->keys()
            ->all();
    }

    /**
     * 验证标题，返回 errors 和处理后的 title
     *
     * @param  string $title
     * @return array         [errors, title]
     */
    protected function filterTitle(string $title): array
    {
        $errors = [];
        $title = strip_tags($title);

        if (!$title) {
            $errors['title'] = '标题不能为空';
        } elseif (!Validator::isMin($title, 2)) {
            $errors['title'] = '标题长度不能小于 2 个字符';
        } elseif (!Validator::isMax($title, 80)) {
            $errors['title'] = '标题长度不能超过 80 个字符';
        }

        return [$errors, $title];
    }

    /**
     * 验证内容，返回 errors 和处理后的 content
     *
     * @param  string $contentMarkdown
     * @param  string $contentRendered
     * @return array                    [errors, contentMarkdown, contentRendered]
     */
    protected function filterContent(string $contentMarkdown, string $contentRendered): array
    {
        $errors = [];

        // 验证正文不能为空
        $contentMarkdown = Html::removeXss($contentMarkdown);
        $contentRendered = Html::removeXss($contentRendered);

        // content_markdown 和 content_rendered 至少需传入一个；都传入时，以 content_markdown 为准
        if (!$contentMarkdown && !$contentRendered) {
            $errors['content_markdown'] = $errors['content_rendered'] = '正文不能为空';
        } elseif (!$contentMarkdown) {
            $contentMarkdown = Html::toMarkdown($contentRendered);
        } else {
            $contentRendered = Markdown::toHtml($contentMarkdown);
        }

        // 验证正文长度
        if (!$errors && !Validator::isMax(strip_tags($contentRendered), 100000)) {
            $errors['content_markdown'] = $errors['content_rendered'] = '正文不能超过 100000 个字';
        }

        return [$errors, $contentMarkdown, $contentRendered];
    }

    /**
     * 更新文章前的字段验证
     *
     * @param  int    $questionId
     * @param  string $title
     * @param  string $contentMarkdown
     * @param  string $contentRendered
     * @param  array  $topicIds
     * @return array                   经过处理后的数据
     */
    protected function updateValidator(
        int    $questionId,
        string $title = null,
        string $contentMarkdown = null,
        string $contentRendered = null,
        array  $topicIds = null
    ): array {
        $this->checkEditPermissions($questionId);

        $errors = [];
        $data = [];

        // 验证标题
        if ($title !== null) {
            [$titleError, $title] = $this->filterTitle($title);

            if ($titleError) {
                $errors = array_merge($errors, $titleError);
            } else {
                $data['title'] = $title;
            }
        }

        // 验证正文
        if ($contentMarkdown !== null || $contentRendered !== null) {
            [$contentError, $contentMarkdown, $contentRendered] = $this->filterContent($contentMarkdown, $contentRendered);

            if ($contentError) {
                $errors = array_merge($errors, $contentError);
            } else {
                $data['content_markdown'] = $contentMarkdown;
                $data['content_rendered'] = $contentRendered;
            }
        }

        if ($errors) {
            throw new ValidationException($errors);
        }

        if ($topicIds !== null) {
            $topicIds = $this->filterTopicIds($topicIds);
        }

        return [$data, $topicIds];
    }
}