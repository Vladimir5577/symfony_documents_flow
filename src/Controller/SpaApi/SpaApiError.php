<?php

declare(strict_types=1);

namespace App\Controller\SpaApi;

final class SpaApiError
{
    public const ACCESS_DENIED = 'access_denied';
    public const ATTACHMENT_LIMIT_REACHED = 'attachment_limit_reached';
    public const ATTACHMENT_NOT_FOUND = 'attachment_not_found';
    public const ATTACHMENT_NOT_PREVIEWABLE = 'attachment_not_previewable';
    public const BOARD_CARD_LIMIT_REACHED = 'board_card_limit_reached';
    public const BOARD_HAS_CARDS = 'board_has_cards';
    public const BOARD_HAS_NO_PROJECT = 'board_has_no_project';
    public const BOARD_NOT_FOUND = 'board_not_found';
    public const BOARD_TITLE_REQUIRED = 'board_title_required';
    public const BOARD_TITLE_TOO_LONG = 'board_title_too_long';
    public const CANNOT_REMOVE_OWNER = 'cannot_remove_owner';
    public const CANNOT_REMOVE_SELF = 'cannot_remove_self';
    public const CARD_NOT_FOUND = 'card_not_found';
    public const COMMENT_AUTHOR_ONLY = 'comment_author_only';
    public const COMMENT_BODY_REQUIRED = 'comment_body_required';
    public const COMMENT_BODY_TOO_LONG = 'comment_body_too_long';
    public const COMMENT_LIMIT_REACHED = 'comment_limit_reached';
    public const COMMENT_FILE_NOT_FOUND = 'comment_file_not_found';
    public const COMMENT_NOT_FOUND = 'comment_not_found';
    public const COMMENT_VALIDATION_FAILED = 'comment_validation_failed';
    public const COLUMN_ID_AND_POSITION_REQUIRED = 'column_id_and_position_required';
    public const COLUMN_ID_AND_TITLE_REQUIRED = 'column_id_and_title_required';
    public const COLUMN_HAS_CARDS = 'column_has_cards';
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
    public const NOTIFICATION_NOT_FOUND = 'notification_not_found';
    public const FOLDER_NOT_FOUND = 'folder_not_found';
    public const FOLDER_NAME_REQUIRED = 'folder_name_required';
    public const FOLDER_NAME_TOO_LONG = 'folder_name_too_long';
    public const PURCHASE_NOT_FOUND = 'purchase_not_found';
    public const PURCHASE_INVALID_STATUS = 'purchase_invalid_status';
    public const PURCHASE_COMMENT_REQUIRED = 'purchase_comment_required';
    public const PURCHASE_ITEMS_REQUIRED = 'purchase_items_required';
    public const PURCHASE_TITLE_REQUIRED = 'purchase_title_required';
    public const PURCHASE_INVALID_PRIORITY = 'purchase_invalid_priority';
    public const PURCHASE_INVALID_ITEM = 'purchase_invalid_item';
    public const PURCHASE_INVALID_DUE_DATE = 'purchase_invalid_due_date';
    public const PURCHASE_FILE_NOT_FOUND = 'purchase_file_not_found';
    public const PURCHASE_FILE_LOCKED = 'purchase_file_locked';
    public const PURCHASE_CATEGORY_NOT_FOUND = 'purchase_category_not_found';
    public const PURCHASE_CATEGORY_IN_USE = 'purchase_category_in_use';
    public const PURCHASE_CATEGORY_NAME_REQUIRED = 'purchase_category_name_required';
    public const PURCHASE_CATEGORY_NAME_TAKEN = 'purchase_category_name_taken';
    public const PURCHASE_IMAGE_INVALID_TYPE = 'purchase_image_invalid_type';
    public const PURCHASE_INVALID_LAW = 'purchase_invalid_law';
    public const PURCHASE_INVALID_METHOD = 'purchase_invalid_method';
    public const PURCHASE_INVALID_FILE_TYPE = 'purchase_invalid_file_type';
    public const PURCHASE_APPROVER_NOT_FOUND = 'purchase_approver_not_found';
    public const PURCHASE_APPROVER_ALREADY_ADDED = 'purchase_approver_already_added';
    // Маршрут согласования
    public const PURCHASE_TASK_NOT_FOUND = 'purchase_task_not_found';
    public const PURCHASE_TASK_NOT_ACTIVE = 'purchase_task_not_active';
    public const PURCHASE_TASK_FILE_REQUIRED = 'purchase_task_file_required';
    public const PURCHASE_TASK_NOT_REVOKABLE = 'purchase_task_not_revokable';
    /** С этого этапа возвращать автору нельзя — например, товар уже оплачен. */
    public const PURCHASE_REJECT_NOT_ALLOWED = 'purchase_reject_not_allowed';
    /** Админ выдаёт участнику роль, которой нет в PurchaseRoleCode. */
    public const PURCHASE_ROLE_NOT_FOUND = 'purchase_role_not_found';
    /** Разбирающий отмечает согласантом того, кто не входит в пул этапа. */
    public const PURCHASE_APPROVER_NOT_DEPUTY = 'purchase_approver_not_deputy';
    /** Подача заявки, маршрут которой не назначен или больше не годится. */
    public const PURCHASE_ROUTE_NOT_CONFIGURED = 'purchase_route_not_configured';
    /** Маршрут заявки нельзя сменить: разбор не активен или дальше уже есть решения. */
    public const PURCHASE_ROUTE_NOT_CHANGEABLE = 'purchase_route_not_changeable';
    /** Двое изменили заявку одновременно — повторить с актуальными данными. */
    public const PURCHASE_CONCURRENT_UPDATE = 'purchase_concurrent_update';
    // Заготовка маршрута в админке
    public const PURCHASE_ROUTE_NOT_FOUND = 'purchase_route_not_found';
    /** Маршрут без этапов не сохраняем: по такому заявка не пойдёт никуда. */
    public const PURCHASE_ROUTE_EMPTY = 'purchase_route_empty';
    /** Машинное имя маршрута занято другой заготовкой. */
    public const PURCHASE_ROUTE_CODE_TAKEN = 'purchase_route_code_taken';
    /**
     * Шапка заготовки не годится: пустое имя, негодный код или не указаны виды заявок.
     *
     * Отдельно от PURCHASE_ROUTE_STAGE_INVALID намеренно: раньше и то и другое
     * отвечало «проверьте этап», и клиент, забывший поле в шапке, отправлял
     * разработчика искать ошибку в дереве этапов, где её не было.
     */
    public const PURCHASE_ROUTE_META_INVALID = 'purchase_route_meta_invalid';
    /** В этапе нет задач, назначения или значения нет в справочнике. */
    public const PURCHASE_ROUTE_STAGE_INVALID = 'purchase_route_stage_invalid';
    /** В задаче нет роли, пула или назначение недопустимо в заготовке. */
    public const PURCHASE_ROUTE_TASK_INVALID = 'purchase_route_task_invalid';
    /** Разборов в маршруте больше одного, либо динамический этап стоит раньше разбора. */
    public const PURCHASE_ROUTE_STAGE_ORDER_INVALID = 'purchase_route_stage_order_invalid';
    /** Заготовку нельзя выключить: она назначена маршрутом по умолчанию. */
    public const PURCHASE_ROUTE_IS_DEFAULT = 'purchase_route_is_default';
    /**
     * Правка маршрута без версии, которую админ видел, когда открывал форму.
     *
     * Отдельно от PURCHASE_CONCURRENT_UPDATE намеренно: иначе клиент, забывший
     * прислать поле, получал бы «вас опередили» и разработчик искал бы второго
     * админа, которого не было.
     */
    public const PURCHASE_ROUTE_VERSION_REQUIRED = 'purchase_route_version_required';
    public const INVENTORY_ACCESS_EXISTS = 'inventory_access_exists';
    public const INVENTORY_ACCESS_NOT_FOUND = 'inventory_access_not_found';
    public const INVENTORY_CATEGORY_HAS_ITEMS = 'inventory_category_has_items';
    public const INVENTORY_CATEGORY_NAME_EXISTS = 'inventory_category_name_exists';
    public const INVENTORY_CATEGORY_NAME_REQUIRED = 'inventory_category_name_required';
    public const INVENTORY_CATEGORY_NOT_ALLOWED = 'inventory_category_not_allowed';
    public const INVENTORY_CATEGORY_NOT_FOUND = 'inventory_category_not_found';
    public const INVENTORY_EXPORT_TOO_LARGE = 'inventory_export_too_large';
    public const INVENTORY_FILE_NOT_FOUND = 'inventory_file_not_found';
    public const INVENTORY_FILE_TOO_LARGE = 'inventory_file_too_large';
    public const INVENTORY_FILE_TYPE_NOT_ALLOWED = 'inventory_file_type_not_allowed';
    public const INVENTORY_FILE_UPLOAD_FAILED = 'inventory_file_upload_failed';
    public const INVENTORY_INVALID_DATE = 'inventory_invalid_date';
    public const INVENTORY_INVALID_PRICE = 'inventory_invalid_price';
    public const INVENTORY_INVALID_QUANTITY = 'inventory_invalid_quantity';
    public const INVENTORY_INVALID_STATUS = 'inventory_invalid_status';
    public const INVENTORY_ITEM_NOT_FOUND = 'inventory_item_not_found';
    public const INVENTORY_ITEM_NUMBER_EXISTS = 'inventory_item_number_exists';
    public const INVENTORY_NO_ACCESS = 'inventory_no_access';
    public const INVENTORY_NOMENCLATURE_HAS_ITEMS = 'inventory_nomenclature_has_items';
    public const INVENTORY_NOMENCLATURE_NAME_EXISTS = 'inventory_nomenclature_name_exists';
    public const INVENTORY_NOMENCLATURE_NAME_REQUIRED = 'inventory_nomenclature_name_required';
    public const INVENTORY_NOMENCLATURE_NOT_FOUND = 'inventory_nomenclature_not_found';
    public const INVENTORY_NOMENCLATURE_REQUIRED = 'inventory_nomenclature_required';
    public const INVENTORY_ORGANIZATION_NOT_ALLOWED = 'inventory_organization_not_allowed';
    public const INVENTORY_UPD_HAS_ITEMS = 'inventory_upd_has_items';
    public const INVENTORY_UPD_NOT_FOUND = 'inventory_upd_not_found';
    public const INVENTORY_UPD_ORGANIZATION_MISMATCH = 'inventory_upd_organization_mismatch';
    public const INVENTORY_UPD_NUMBER_REQUIRED = 'inventory_upd_number_required';
    public const INVENTORY_USER_NOT_IN_ORGANIZATION = 'inventory_user_not_in_organization';
}
