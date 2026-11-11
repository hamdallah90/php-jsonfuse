#ifndef JF_PLAN_H
#define JF_PLAN_H

#include "php.h"

#include <stdbool.h>

#define JF_MAX_FIELDS   128
#define JF_MAX_FILTERS  16
/* Segments of one path. */
#define JF_MAX_PATH     8
/* Distinct path nodes a plan can hold: every segment of every entry). */
#define JF_MAX_NODES    ((JF_MAX_FIELDS + JF_MAX_FILTERS) * JF_MAX_PATH)
/* The flat table: the slot table of every plan up to 170 nodes, and so of every flat plan (at most 144). */
#define JF_ROOT_SLOTS   512
/* Names, path segments and string values, in bytes before escaping. */
#define JF_MAX_NAME_BYTES    1024
#define JF_MAX_RECORD_BYTES  67108864
/* The longest escaped form of one byte, \u00XX. */
#define JF_MAX_ESCAPE        6

typedef enum { JF_INT64, JF_FLOAT64, JF_BOOL, JF_STRING } jf_type;

typedef enum { JF_FILTER_STRING, JF_FILTER_INT, JF_FILTER_FLOAT, JF_FILTER_BOOL, JF_FILTER_NULL } jf_filter_kind;

/* The where operators; each appears at most once per entry, so an entry has at most six. */
typedef enum { JF_OP_EQ, JF_OP_GT, JF_OP_GE, JF_OP_LT, JF_OP_LE, JF_OP_IN } jf_op;
#define JF_MAX_OPS      6
#define JF_MAX_MEMBERS  256

/* An entry's path: literal key bytes, top level first; [name] when the spec gives none. */
typedef struct {
	char *segment[JF_MAX_PATH];     /* owned; the first depth entries */
	size_t length[JF_MAX_PATH];
	uint16_t node[JF_MAX_PATH];     /* the node of each segment; node[depth - 1] is the leaf */
	uint8_t depth;                  /* 1 to JF_MAX_PATH once parsed */
} jf_path;

typedef struct {
	char *name;                 /* owned */
	size_t name_length;
	char *escaped;              /* owned, NUL-terminated: safe to print in messages */
	char *prefix;               /* owned: the name as output, with its comma: ,"name": */
	size_t prefix_length;
	char *default_out;          /* owned: the default as output, built at compile; NULL without a default */
	size_t default_length;
	jf_type type;
	bool nullable;
	uint16_t leaf;              /* path.node[path.depth - 1], beside the members the writer reads */
	jf_path path;
} jf_field_spec;

/* A where operand: an '=' value, an 'in' member or a range bound (a string, int or float). */
typedef struct {
	jf_filter_kind kind;
	bool b;                     /* JF_FILTER_BOOL */
	char *str;                  /* owned; JF_FILTER_STRING only */
	size_t str_length;
	zend_long i;                /* JF_FILTER_INT */
	double d;                   /* JF_FILTER_FLOAT */
} jf_scalar;

typedef struct {
	jf_op op;
	jf_scalar value;            /* every operator but in */
	jf_scalar *members;         /* owned; in only, member_count entries */
	size_t member_count;
} jf_operator;

/* A where entry: its operators in declared order, all of which must hold. */
typedef struct {
	uint16_t leaf;              /* path.node[path.depth - 1], beside the members a run reads */
	uint8_t op_count;
	jf_operator op[JF_MAX_OPS];
	char *name;                 /* owned */
	size_t name_length;
	char *escaped;              /* owned, NUL-terminated */
	jf_path path;
} jf_filter_spec;

/*
 * One distinct key under one parent that some path passes through. Node 0 is the record's
 * root object. A node is inner (a path continues below it) or a leaf, never both, because
 * Plan::compile refuses a path that is a strict prefix of another.
 */
typedef struct {
	const char *key;            /* borrowed from the path of the entry that created the node */
	size_t key_length;
	uint16_t parent;
	bool inner;
	bool field_leaf;            /* a fields entry ends here */
	bool filter_leaf;           /* a where entry ends here */
	bool named;                 /* a top-level key equal to its owner's name */
	/* The first fields entry, else the first where entry, whose path passes through: getField() of duplicate_key. */
	const char *owner;
	size_t owner_length;
	const char *owner_escaped;
} jf_node;

typedef struct {
	size_t field_count;
	jf_field_spec *fields;
	size_t filter_count;
	jf_filter_spec *filters;
	size_t node_count;          /* nodes 1 to node_count; nodes[0] is the root */
	jf_node *nodes;             /* owned */
	uint16_t *slots;            /* slot_mask + 1 entries: node id, 0 is empty; root_slots, else owned */
	uint32_t slot_mask;
	bool flat;                  /* no inner node: every path has one segment, so jf_walk reads only the root */
	uint16_t root_slots[JF_ROOT_SLOTS];
	size_t prefix_total;        /* sum of fields' prefix_length */
	size_t default_total;       /* sum of fields' default_length */
	size_t max_record_bytes;
	bool skip_empty_lines;
} jf_plan_data;

typedef struct {
	jf_plan_data data;          /* partially built until compiled */
	bool compiled;
	zend_object std;
} jf_plan_object;

extern zend_class_entry *jf_ce_Plan;

static zend_always_inline jf_plan_object *jf_plan_from_obj(zend_object *obj)
{
	return (jf_plan_object *)((char *)obj - offsetof(jf_plan_object, std));
}

/*
 * Limit: FNV-1a over the whole key, so a record key costs O(its length), which parsing
 * already paid. The basis mixes in the parent node, so a key is looked up only under its own
 * parent; the root's keys hash as plain FNV-1a. Records cannot add to the table,
 * which keeps at least two empty slots per node, so only a plan whose own keys collide
 * lengthens a probe, and never past its node count.
 */
static zend_always_inline uint32_t jf_key_hash(uint16_t parent, const char *key, size_t length)
{
	uint32_t h = 2166136261u ^ ((uint32_t)parent * 0x9E3779B1u);
	size_t i;

	for (i = 0; i < length; i++) {
		h = (h ^ (unsigned char)key[i]) * 16777619u;
	}
	return h;
}

/* The node of a key given as decoded bytes (\u0000 is legal) under parent, or 0. */
static zend_always_inline uint16_t jf_node_find(const jf_plan_data *d, uint16_t parent, const char *key, size_t length)
{
	uint32_t i;

	for (i = jf_key_hash(parent, key, length) & d->slot_mask; d->slots[i] != 0; i = (i + 1) & d->slot_mask) {
		const jf_node *n = &d->nodes[d->slots[i]];

		if (n->parent == parent && n->key_length == length && memcmp(n->key, key, length) == 0) {
			return d->slots[i];
		}
	}
	return 0;
}

/*
 * jf_node_find under the root of a flat plan: the flat lookup. Its nodes all have the root as
 * parent, and its table is root_slots, so the mask is a constant.
 */
static zend_always_inline uint16_t jf_root_find(const jf_plan_data *d, const char *key, size_t length)
{
	uint32_t i;

	for (i = jf_key_hash(0, key, length) & (JF_ROOT_SLOTS - 1); d->root_slots[i] != 0; i = (i + 1) & (JF_ROOT_SLOTS - 1)) {
		const jf_node *n = &d->nodes[d->root_slots[i]];

		if (n->key_length == length && memcmp(n->key, key, length) == 0) {
			return d->root_slots[i];
		}
	}
	return 0;
}

/* Writes n bytes through the SPEC.md section 6 escape table; p needs room for 6 * n. Returns the end. */
char *jf_escape_bytes(char *p, const char *s, size_t n);

/* The exact number of bytes jf_escape_bytes writes for these n bytes. */
size_t jf_escaped_length(const char *s, size_t n);

void jf_plan_minit(void);

#endif
