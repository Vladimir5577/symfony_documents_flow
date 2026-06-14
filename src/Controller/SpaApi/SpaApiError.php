<?php

declare(strict_types=1);

namespace App\Controller\SpaApi;

final class SpaApiError
{
    public const ACCESS_DENIED = 'access_denied';
    public const ATTACHMENT_LIMIT_REACHED = 'attachment_limit_reached';
    public const ATTACHMENT_NOT_FOUND = 'attachment_not_found';
    public const ATTACHMENT_NOT_PREVIEWABLE = 'attachment_not_previewable';
    public const BOARD_HAS_NO_PROJECT = 'board_has_no_project';
    public const BOARD_NOT_FOUND = 'board_not_found';
    public const BOARD_TITLE_REQUIRED = 'board_title_required';
    public const BOARD_TITLE_TOO_LONG = 'board_title_too_long';
    public const CANNOT_REMOVE_OWNER = 'cannot_remove_owner';
    public const CANNOT_REMOVE_SELF = 'cannot_remove_self';
    public const CARD_NOT_FOUND = 'card_not_found';
    public const COMMENT_AUTHOR_ONLY = 'comment_author_only';
    public const COMMENT_BODY_REQUIRED = 'comment_body_required';
    public const COMMENT_NOT_FOUND = 'comment_not_found';
    public const COLUMN_ID_AND_POSITION_REQUIRED = 'column_id_and_position_required';
    public const COLUMN_ID_AND_TITLE_REQUIRED = 'column_id_and_title_required';
    public const COLUMN_NOT_FOUND = 'column_not_found';
    public const COLUMN_TITLE_REQUIRED = 'column_title_required';
    public const DESCRIPTION_INVALID_TYPE = 'description_invalid_type';
    public const FILE_NOT_FOUND_ON_DISK = 'file_not_found_on_disk';
    public const FILE_NOT_PROVIDED = 'file_not_provided';
    public const INSUFFICIENT_PERMISSIONS = 'insufficient_permissions';
    public const INVALID_JSON = 'invalid_json';
    public const LABEL_NAME_REQUIRED = 'label_name_required';
    public const LABEL_NOT_FOUND = 'label_not_found';
    public const INVALID_ROLE = 'invalid_role';
    public const INVALID_ROLE_FOR_USER = 'invalid_role_for_user';
    public const MEMBER_NOT_FOUND = 'member_not_found';
    public const MEMBERS_ARRAY_EXPECTED = 'members_array_expected';
    public const MEMBERS_LIST_EMPTY = 'members_list_empty';
    public const ORGANIZATION_NOT_FOUND = 'organization_not_found';
    public const OWNER_ROLE_IMMUTABLE = 'owner_role_immutable';
    public const PROJECT_ACCESS_DENIED = 'project_access_denied';
    public const PROJECT_CREATE_FAILED = 'project_create_failed';
    public const PROJECT_NAME_REQUIRED = 'project_name_required';
    public const PROJECT_NAME_TOO_LONG = 'project_name_too_long';
    public const PROJECT_NOT_FOUND = 'project_not_found';
    public const SUBTASK_NOT_FOUND = 'subtask_not_found';
    public const SUBTASK_TITLE_REQUIRED = 'subtask_title_required';
    public const UPDATE_FIELDS_REQUIRED = 'update_fields_required';
    public const USER_NOT_FOUND = 'user_not_found';
    public const USER_NOT_PROJECT_MEMBER = 'user_not_project_member';
    public const DOCUMENT_NOT_FOUND = 'document_not_found';
    public const DOCUMENT_TYPE_NOT_FOUND = 'document_type_not_found';
    public const DOCUMENT_NAME_REQUIRED = 'document_name_required';
    public const DOCUMENT_INVALID_STATUS = 'document_invalid_status';
    public const DOCUMENT_INVALID_DEADLINE = 'document_invalid_deadline';
    public const DOCUMENT_CANNOT_PUBLISH_DRAFT = 'document_cannot_publish_draft';
    public const DOCUMENT_NO_RECIPIENTS = 'document_no_recipients';
    public const DOCUMENT_VALIDATION_FAILED = 'document_validation_failed';
    public const ORGANIZATION_REQUIRED = 'organization_required';
    public const POST_NOT_FOUND = 'post_not_found';
    public const POST_TITLE_REQUIRED = 'post_title_required';
    public const POST_TYPE_REQUIRED = 'post_type_required';
    public const POST_INVALID_TYPE = 'post_invalid_type';
    public const POST_COVER_INVALID_IMAGE = 'post_cover_invalid_image';
    public const POST_COVER_TOO_LARGE = 'post_cover_too_large';
    public const POST_FILE_TOO_LARGE = 'post_file_too_large';
    public const POST_FILE_UPLOAD_ERROR = 'post_file_upload_error';
    public const POST_COMMENT_EMPTY = 'post_comment_empty';
    public const POST_FILE_NOT_FOUND = 'post_file_not_found';
    public const POST_FILE_NOT_FOUND_ON_DISK = 'post_file_not_found_on_disk';
}
