/* This is a generated file, edit the .stub.php file instead.
 * Stub hash: f600b7b596fb0e634dd978fa560d5f2eec65ed44 */

ZEND_BEGIN_ARG_INFO_EX(arginfo_class_JsonFuse_Plan___construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_JsonFuse_Plan_compile, 0, 1, JsonFuse\\Plan, 0)
	ZEND_ARG_TYPE_INFO(0, spec, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_JsonFuse_Stats___construct arginfo_class_JsonFuse_Plan___construct

#define arginfo_class_JsonFuse_Runner___construct arginfo_class_JsonFuse_Plan___construct

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_JsonFuse_Runner_process, 0, 3, JsonFuse\\Stats, 0)
	ZEND_ARG_INFO(0, input)
	ZEND_ARG_INFO(0, output)
	ZEND_ARG_OBJ_INFO(0, plan, JsonFuse\\Plan, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_JsonFuse_JsonFuseException_getReason, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_JsonFuse_JsonFuseException_getField, 0, 0, IS_STRING, 1)
ZEND_END_ARG_INFO()

#define arginfo_class_JsonFuse_PlanException_getOption arginfo_class_JsonFuse_JsonFuseException_getField

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_class_JsonFuse_RecordException_getInputLine, 0, 0, IS_LONG, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_JsonFuse_RecordException_getRecordNumber arginfo_class_JsonFuse_RecordException_getInputLine

#define arginfo_class_JsonFuse_RecordException_getByteOffset arginfo_class_JsonFuse_RecordException_getInputLine

ZEND_BEGIN_ARG_WITH_RETURN_OBJ_INFO_EX(arginfo_class_JsonFuse_RecordException_getProgress, 0, 0, JsonFuse\\Stats, 0)
ZEND_END_ARG_INFO()

#define arginfo_class_JsonFuse_StreamException_getInputLine arginfo_class_JsonFuse_RecordException_getInputLine

#define arginfo_class_JsonFuse_StreamException_getRecordNumber arginfo_class_JsonFuse_RecordException_getInputLine

#define arginfo_class_JsonFuse_StreamException_getByteOffset arginfo_class_JsonFuse_RecordException_getInputLine

#define arginfo_class_JsonFuse_StreamException_getProgress arginfo_class_JsonFuse_RecordException_getProgress


ZEND_METHOD(JsonFuse_Plan, __construct);
ZEND_METHOD(JsonFuse_Plan, compile);
ZEND_METHOD(JsonFuse_Stats, __construct);
ZEND_METHOD(JsonFuse_Runner, __construct);
ZEND_METHOD(JsonFuse_Runner, process);
ZEND_METHOD(JsonFuse_JsonFuseException, getReason);
ZEND_METHOD(JsonFuse_JsonFuseException, getField);
ZEND_METHOD(JsonFuse_PlanException, getOption);
ZEND_METHOD(JsonFuse_RecordException, getInputLine);
ZEND_METHOD(JsonFuse_RecordException, getRecordNumber);
ZEND_METHOD(JsonFuse_RecordException, getByteOffset);
ZEND_METHOD(JsonFuse_RecordException, getProgress);


static const zend_function_entry class_JsonFuse_Plan_methods[] = {
	ZEND_ME(JsonFuse_Plan, __construct, arginfo_class_JsonFuse_Plan___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(JsonFuse_Plan, compile, arginfo_class_JsonFuse_Plan_compile, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_FE_END
};


static const zend_function_entry class_JsonFuse_Stats_methods[] = {
	ZEND_ME(JsonFuse_Stats, __construct, arginfo_class_JsonFuse_Stats___construct, ZEND_ACC_PRIVATE)
	ZEND_FE_END
};


static const zend_function_entry class_JsonFuse_Runner_methods[] = {
	ZEND_ME(JsonFuse_Runner, __construct, arginfo_class_JsonFuse_Runner___construct, ZEND_ACC_PRIVATE)
	ZEND_ME(JsonFuse_Runner, process, arginfo_class_JsonFuse_Runner_process, ZEND_ACC_PUBLIC|ZEND_ACC_STATIC)
	ZEND_FE_END
};


static const zend_function_entry class_JsonFuse_JsonFuseException_methods[] = {
	ZEND_ME(JsonFuse_JsonFuseException, getReason, arginfo_class_JsonFuse_JsonFuseException_getReason, ZEND_ACC_PUBLIC)
	ZEND_ME(JsonFuse_JsonFuseException, getField, arginfo_class_JsonFuse_JsonFuseException_getField, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_JsonFuse_PlanException_methods[] = {
	ZEND_ME(JsonFuse_PlanException, getOption, arginfo_class_JsonFuse_PlanException_getOption, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_JsonFuse_RecordException_methods[] = {
	ZEND_ME(JsonFuse_RecordException, getInputLine, arginfo_class_JsonFuse_RecordException_getInputLine, ZEND_ACC_PUBLIC)
	ZEND_ME(JsonFuse_RecordException, getRecordNumber, arginfo_class_JsonFuse_RecordException_getRecordNumber, ZEND_ACC_PUBLIC)
	ZEND_ME(JsonFuse_RecordException, getByteOffset, arginfo_class_JsonFuse_RecordException_getByteOffset, ZEND_ACC_PUBLIC)
	ZEND_ME(JsonFuse_RecordException, getProgress, arginfo_class_JsonFuse_RecordException_getProgress, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};


static const zend_function_entry class_JsonFuse_StreamException_methods[] = {
	ZEND_MALIAS(JsonFuse_RecordException, getInputLine, getInputLine, arginfo_class_JsonFuse_StreamException_getInputLine, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(JsonFuse_RecordException, getRecordNumber, getRecordNumber, arginfo_class_JsonFuse_StreamException_getRecordNumber, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(JsonFuse_RecordException, getByteOffset, getByteOffset, arginfo_class_JsonFuse_StreamException_getByteOffset, ZEND_ACC_PUBLIC)
	ZEND_MALIAS(JsonFuse_RecordException, getProgress, getProgress, arginfo_class_JsonFuse_StreamException_getProgress, ZEND_ACC_PUBLIC)
	ZEND_FE_END
};

static zend_class_entry *register_class_JsonFuse_Plan(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "JsonFuse", "Plan", class_JsonFuse_Plan_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE;

	return class_entry;
}

static zend_class_entry *register_class_JsonFuse_Stats(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "JsonFuse", "Stats", class_JsonFuse_Stats_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES;

	zval property_recordsRead_default_value;
	ZVAL_UNDEF(&property_recordsRead_default_value);
	zend_string *property_recordsRead_name = zend_string_init("recordsRead", sizeof("recordsRead") - 1, 1);
	zend_declare_typed_property(class_entry, property_recordsRead_name, &property_recordsRead_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_recordsRead_name);

	zval property_recordsMatched_default_value;
	ZVAL_UNDEF(&property_recordsMatched_default_value);
	zend_string *property_recordsMatched_name = zend_string_init("recordsMatched", sizeof("recordsMatched") - 1, 1);
	zend_declare_typed_property(class_entry, property_recordsMatched_name, &property_recordsMatched_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_recordsMatched_name);

	zval property_recordsEmitted_default_value;
	ZVAL_UNDEF(&property_recordsEmitted_default_value);
	zend_string *property_recordsEmitted_name = zend_string_init("recordsEmitted", sizeof("recordsEmitted") - 1, 1);
	zend_declare_typed_property(class_entry, property_recordsEmitted_name, &property_recordsEmitted_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_recordsEmitted_name);

	zval property_bytesRead_default_value;
	ZVAL_UNDEF(&property_bytesRead_default_value);
	zend_string *property_bytesRead_name = zend_string_init("bytesRead", sizeof("bytesRead") - 1, 1);
	zend_declare_typed_property(class_entry, property_bytesRead_name, &property_bytesRead_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_bytesRead_name);

	zval property_bytesWritten_default_value;
	ZVAL_UNDEF(&property_bytesWritten_default_value);
	zend_string *property_bytesWritten_name = zend_string_init("bytesWritten", sizeof("bytesWritten") - 1, 1);
	zend_declare_typed_property(class_entry, property_bytesWritten_name, &property_bytesWritten_default_value, ZEND_ACC_PUBLIC|ZEND_ACC_READONLY, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_bytesWritten_name);

	return class_entry;
}

static zend_class_entry *register_class_JsonFuse_Runner(void)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "JsonFuse", "Runner", class_JsonFuse_Runner_methods);
	class_entry = zend_register_internal_class_ex(&ce, NULL);
	class_entry->ce_flags |= ZEND_ACC_FINAL|ZEND_ACC_NO_DYNAMIC_PROPERTIES|ZEND_ACC_NOT_SERIALIZABLE;

	return class_entry;
}

static zend_class_entry *register_class_JsonFuse_JsonFuseException(zend_class_entry *class_entry_RuntimeException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "JsonFuse", "JsonFuseException", class_JsonFuse_JsonFuseException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_RuntimeException);

	zval property_reason_default_value;
	ZVAL_EMPTY_STRING(&property_reason_default_value);
	zend_string *property_reason_name = zend_string_init("reason", sizeof("reason") - 1, 1);
	zend_declare_typed_property(class_entry, property_reason_name, &property_reason_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING));
	zend_string_release(property_reason_name);

	zval property_field_default_value;
	ZVAL_NULL(&property_field_default_value);
	zend_string *property_field_name = zend_string_init("field", sizeof("field") - 1, 1);
	zend_declare_typed_property(class_entry, property_field_name, &property_field_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING|MAY_BE_NULL));
	zend_string_release(property_field_name);

	return class_entry;
}

static zend_class_entry *register_class_JsonFuse_PlanException(zend_class_entry *class_entry_JsonFuse_JsonFuseException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "JsonFuse", "PlanException", class_JsonFuse_PlanException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_JsonFuse_JsonFuseException);

	zval property_option_default_value;
	ZVAL_NULL(&property_option_default_value);
	zend_string *property_option_name = zend_string_init("option", sizeof("option") - 1, 1);
	zend_declare_typed_property(class_entry, property_option_name, &property_option_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_STRING|MAY_BE_NULL));
	zend_string_release(property_option_name);

	return class_entry;
}

static zend_class_entry *register_class_JsonFuse_RecordException(zend_class_entry *class_entry_JsonFuse_JsonFuseException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "JsonFuse", "RecordException", class_JsonFuse_RecordException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_JsonFuse_JsonFuseException);

	zval property_inputLine_default_value;
	ZVAL_LONG(&property_inputLine_default_value, 0);
	zend_string *property_inputLine_name = zend_string_init("inputLine", sizeof("inputLine") - 1, 1);
	zend_declare_typed_property(class_entry, property_inputLine_name, &property_inputLine_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_inputLine_name);

	zval property_recordNumber_default_value;
	ZVAL_LONG(&property_recordNumber_default_value, 0);
	zend_string *property_recordNumber_name = zend_string_init("recordNumber", sizeof("recordNumber") - 1, 1);
	zend_declare_typed_property(class_entry, property_recordNumber_name, &property_recordNumber_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_recordNumber_name);

	zval property_byteOffset_default_value;
	ZVAL_LONG(&property_byteOffset_default_value, 0);
	zend_string *property_byteOffset_name = zend_string_init("byteOffset", sizeof("byteOffset") - 1, 1);
	zend_declare_typed_property(class_entry, property_byteOffset_name, &property_byteOffset_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_byteOffset_name);

	zval property_progress_default_value;
	ZVAL_NULL(&property_progress_default_value);
	zend_string *property_progress_name = zend_string_init("progress", sizeof("progress") - 1, 1);
	zend_string *property_progress_class_JsonFuse_Stats = zend_string_init("JsonFuse\\Stats", sizeof("JsonFuse\\Stats")-1, 1);
	zend_declare_typed_property(class_entry, property_progress_name, &property_progress_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_progress_class_JsonFuse_Stats, 0, MAY_BE_NULL));
	zend_string_release(property_progress_name);

	return class_entry;
}

static zend_class_entry *register_class_JsonFuse_StreamException(zend_class_entry *class_entry_JsonFuse_JsonFuseException)
{
	zend_class_entry ce, *class_entry;

	INIT_NS_CLASS_ENTRY(ce, "JsonFuse", "StreamException", class_JsonFuse_StreamException_methods);
	class_entry = zend_register_internal_class_ex(&ce, class_entry_JsonFuse_JsonFuseException);

	zval property_inputLine_default_value;
	ZVAL_LONG(&property_inputLine_default_value, 0);
	zend_string *property_inputLine_name = zend_string_init("inputLine", sizeof("inputLine") - 1, 1);
	zend_declare_typed_property(class_entry, property_inputLine_name, &property_inputLine_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_inputLine_name);

	zval property_recordNumber_default_value;
	ZVAL_LONG(&property_recordNumber_default_value, 0);
	zend_string *property_recordNumber_name = zend_string_init("recordNumber", sizeof("recordNumber") - 1, 1);
	zend_declare_typed_property(class_entry, property_recordNumber_name, &property_recordNumber_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_recordNumber_name);

	zval property_byteOffset_default_value;
	ZVAL_LONG(&property_byteOffset_default_value, 0);
	zend_string *property_byteOffset_name = zend_string_init("byteOffset", sizeof("byteOffset") - 1, 1);
	zend_declare_typed_property(class_entry, property_byteOffset_name, &property_byteOffset_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_MASK(MAY_BE_LONG));
	zend_string_release(property_byteOffset_name);

	zval property_progress_default_value;
	ZVAL_NULL(&property_progress_default_value);
	zend_string *property_progress_name = zend_string_init("progress", sizeof("progress") - 1, 1);
	zend_string *property_progress_class_JsonFuse_Stats = zend_string_init("JsonFuse\\Stats", sizeof("JsonFuse\\Stats")-1, 1);
	zend_declare_typed_property(class_entry, property_progress_name, &property_progress_default_value, ZEND_ACC_PRIVATE, NULL, (zend_type) ZEND_TYPE_INIT_CLASS(property_progress_class_JsonFuse_Stats, 0, MAY_BE_NULL));
	zend_string_release(property_progress_name);

	return class_entry;
}
