<?php

declare(strict_types=1);

namespace App\Controller\SpaApi;

final class SpaApiError
{
    public const ACCESS_DENIED = 'access_denied';
    public const ATTACHMENT_NOT_FOUND = 'attachment_not_found';
    public const COMMENT_AUTHOR_ONLY = 'comment_author_only';
    public const COMMENT_BODY_REQUIRED = 'comment_body_required';
    public const COMMENT_BODY_TOO_LONG = 'comment_body_too_long';
    public const COMMENT_FILE_NOT_FOUND = 'comment_file_not_found';
    public const COMMENT_NOT_FOUND = 'comment_not_found';
    public const COMMENT_VALIDATION_FAILED = 'comment_validation_failed';
    public const FILE_NOT_FOUND_ON_DISK = 'file_not_found_on_disk';
    public const FILE_NOT_PROVIDED = 'file_not_provided';
    public const INVALID_CREDENTIALS = 'invalid_credentials';
    public const INVALID_JSON = 'invalid_json';
    public const ORGANIZATION_NOT_FOUND = 'organization_not_found';
    /** Единственная ошибка SPA API с ответом 429: сработал login_throttling. */
    public const TOO_MANY_LOGIN_ATTEMPTS = 'too_many_login_attempts';
    public const UPDATE_FIELDS_REQUIRED = 'update_fields_required';
    public const USER_NOT_FOUND = 'user_not_found';
    public const DOCUMENT_NOT_FOUND = 'document_not_found';
    public const DOCUMENT_TYPE_NOT_FOUND = 'document_type_not_found';
    public const DOCUMENT_NAME_REQUIRED = 'document_name_required';
    public const DOCUMENT_INVALID_STATUS = 'document_invalid_status';
    public const DOCUMENT_INVALID_DEADLINE = 'document_invalid_deadline';
    public const DOCUMENT_CANNOT_PUBLISH_DRAFT = 'document_cannot_publish_draft';
    public const DOCUMENT_NO_RECIPIENTS = 'document_no_recipients';
    public const DOCUMENT_VALIDATION_FAILED = 'document_validation_failed';
    /** Файл для редактора — только у черновика, который можно править. */
    public const DOCUMENT_OFFICE_DRAFT_ONLY = 'document_office_draft_only';
    /** Файл не docx и не xlsx. */
    public const DOCUMENT_FILE_NOT_DOCX = 'document_file_not_docx';
    public const DOCUMENT_FILE_EDITOR_FAILED = 'document_file_editor_failed';
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
    public const INVENTORY_ACCESS_EXISTS = 'inventory_access_exists';
    public const INVENTORY_ACCESS_NOT_FOUND = 'inventory_access_not_found';
    public const INVENTORY_CATEGORY_HAS_ITEMS = 'inventory_category_has_items';
    public const INVENTORY_CATEGORY_NAME_EXISTS = 'inventory_category_name_exists';
    public const INVENTORY_CATEGORY_NAME_REQUIRED = 'inventory_category_name_required';
    public const INVENTORY_CATEGORY_NOT_ALLOWED = 'inventory_category_not_allowed';
    public const INVENTORY_CATEGORY_NOT_FOUND = 'inventory_category_not_found';
    /** Родителем выбрана сама категория или её потомок — получился бы цикл. */
    public const INVENTORY_CATEGORY_PARENT_INVALID = 'inventory_category_parent_invalid';
    /** Вложенность превысила CategoryTree::MAX_DEPTH. */
    public const INVENTORY_CATEGORY_TOO_DEEP = 'inventory_category_too_deep';
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

    // Документы к ознакомлению
    public const ACK_CATEGORY_HAS_DOCUMENTS = 'ack_category_has_documents';
    public const ACK_CATEGORY_NAME_REQUIRED = 'ack_category_name_required';
    public const ACK_CATEGORY_NAME_TAKEN = 'ack_category_name_taken';
    public const ACK_CATEGORY_NOT_FOUND = 'ack_category_not_found';
    public const ACK_DOCUMENT_ALREADY_PUBLISHED = 'ack_document_already_published';
    public const ACK_DOCUMENT_AUDIENCE_INVALID = 'ack_document_audience_invalid';
    public const ACK_DOCUMENT_DEADLINE_INVALID = 'ack_document_deadline_invalid';
    /** Действие требует опубликованного и неархивного документа. */
    public const ACK_DOCUMENT_NOT_ACTIVE = 'ack_document_not_active';
    public const ACK_DOCUMENT_NOT_FOUND = 'ack_document_not_found';
    public const ACK_DOCUMENT_NO_FILES = 'ack_document_no_files';
    public const ACK_DOCUMENT_NO_RECIPIENTS = 'ack_document_no_recipients';
    public const ACK_DOCUMENT_TITLE_REQUIRED = 'ack_document_title_required';
    public const ACK_FILE_NOT_FOUND = 'ack_file_not_found';
    public const ACK_FILE_TOO_LARGE = 'ack_file_too_large';
    public const ACK_FILE_TYPE_NOT_ALLOWED = 'ack_file_type_not_allowed';
    /** Ознакомление уже отмечено: назад его не отыгрывают. */
    public const ACK_STATUS_ALREADY_FINAL = 'ack_status_already_final';
    public const ACK_STATUS_COMMENT_REQUIRED = 'ack_status_comment_required';
    public const ACK_STATUS_INVALID = 'ack_status_invalid';
}
