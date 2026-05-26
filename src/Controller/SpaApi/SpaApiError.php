<?php

declare(strict_types=1);

namespace App\Controller\SpaApi;

final class SpaApiError
{
    public const ACCESS_DENIED = 'access_denied';
    public const BOARD_HAS_NO_PROJECT = 'board_has_no_project';
    public const BOARD_NOT_FOUND = 'board_not_found';
    public const BOARD_TITLE_REQUIRED = 'board_title_required';
    public const BOARD_TITLE_TOO_LONG = 'board_title_too_long';
    public const CANNOT_REMOVE_OWNER = 'cannot_remove_owner';
    public const CANNOT_REMOVE_SELF = 'cannot_remove_self';
    public const CARD_NOT_FOUND = 'card_not_found';
    public const COLUMN_ID_AND_POSITION_REQUIRED = 'column_id_and_position_required';
    public const COLUMN_ID_AND_TITLE_REQUIRED = 'column_id_and_title_required';
    public const COLUMN_NOT_FOUND = 'column_not_found';
    public const DESCRIPTION_INVALID_TYPE = 'description_invalid_type';
    public const INSUFFICIENT_PERMISSIONS = 'insufficient_permissions';
    public const INVALID_JSON = 'invalid_json';
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
    public const UPDATE_FIELDS_REQUIRED = 'update_fields_required';
    public const USER_NOT_FOUND = 'user_not_found';
    public const USER_NOT_PROJECT_MEMBER = 'user_not_project_member';
}
