#ifndef JF_RUNNER_H
#define JF_RUNNER_H

#include "php.h"

#include "jf_errors.h"

/* Fiber switches on this thread, counted by the observer registered at MINIT. */
ZEND_BEGIN_MODULE_GLOBALS(jsonfuse)
	uint64_t fiber_switches;
ZEND_END_MODULE_GLOBALS(jsonfuse)

ZEND_EXTERN_MODULE_GLOBALS(jsonfuse)
#define JF_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(jsonfuse, v)

/* False when the module was loaded by dl(), which does not register the observer. */
extern bool jf_fiber_switches_observed;

extern zend_class_entry *jf_ce_Stats;

/* A new Stats object holding these counts. */
void jf_stats_new(zval *rv, const jf_progress *p);

#endif
