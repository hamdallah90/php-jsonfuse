#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_observer.h"
#include "ext/spl/spl_exceptions.h"
#include "ext/standard/info.h"

#include "yyjson.h"

#include "jf_errors.h"
#include "jf_parse.h"
#include "jf_plan.h"
#include "jf_runner.h"

#define PHP_JSONFUSE_VERSION "1.0.0"

#include "jsonfuse_arginfo.h"

ZEND_DECLARE_MODULE_GLOBALS(jsonfuse)

/* Every switch on this thread: start, suspend, resume, return and destruction. */
static void jf_fiber_switched(zend_fiber_context *from, zend_fiber_context *to)
{
	(void)from;
	(void)to;
	JF_G(fiber_switches)++;
}

static PHP_GINIT_FUNCTION(jsonfuse)
{
	jsonfuse_globals->fiber_switches = 0;
}

ZEND_METHOD(JsonFuse_Plan, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(JsonFuse_Stats, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

ZEND_METHOD(JsonFuse_Runner, __construct)
{
	(void)return_value;
	ZEND_PARSE_PARAMETERS_NONE();
}

static PHP_MINIT_FUNCTION(jsonfuse)
{
	(void)module_number;

	/*
	 * The observer list has no removal, and dl() unloads the module at request end,
	 * so a handler registered then would dangle in the next request.
	 */
	if (type == MODULE_PERSISTENT) {
		zend_observer_fiber_switch_register(jf_fiber_switched);
		jf_fiber_switches_observed = true;
	}

	jf_ce_JsonFuseException = register_class_JsonFuse_JsonFuseException(spl_ce_RuntimeException);
	jf_ce_PlanException = register_class_JsonFuse_PlanException(jf_ce_JsonFuseException);
	jf_ce_RecordException = register_class_JsonFuse_RecordException(jf_ce_JsonFuseException);
	jf_ce_StreamException = register_class_JsonFuse_StreamException(jf_ce_JsonFuseException);

	jf_ce_Plan = register_class_JsonFuse_Plan();
	jf_plan_minit();
	jf_ce_Stats = register_class_JsonFuse_Stats();
	register_class_JsonFuse_Runner();

	return SUCCESS;
}

static PHP_MINFO_FUNCTION(jsonfuse)
{
	(void)zend_module;

	php_info_print_table_start();
	php_info_print_table_row(2, "jsonfuse support", "enabled");
	php_info_print_table_row(2, "version", PHP_JSONFUSE_VERSION);
	php_info_print_table_row(2, "yyjson", YYJSON_VERSION_STRING);
#ifdef JF_DISABLE_SCAN
	php_info_print_table_row(2, "validating scan", "disabled");
#else
	php_info_print_table_row(2, "validating scan", "enabled");
#ifdef JF_SCAN_STATS
	{
		char accepted[24];

		snprintf(accepted, sizeof accepted, "%" PRIu64, jf_scan_accepted_count());
		php_info_print_table_row(2, "validating scan accepted", accepted);
	}
#endif
#endif
	php_info_print_table_end();
}

zend_module_entry jsonfuse_module_entry = {
	STANDARD_MODULE_HEADER,
	"jsonfuse",
	NULL,
	PHP_MINIT(jsonfuse),
	NULL,
	NULL,
	NULL,
	PHP_MINFO(jsonfuse),
	PHP_JSONFUSE_VERSION,
	PHP_MODULE_GLOBALS(jsonfuse),
	PHP_GINIT(jsonfuse),
	NULL,
	NULL,
	STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_JSONFUSE
ZEND_GET_MODULE(jsonfuse)
#endif
