#!/usr/bin/env python3
"""Regenerates corpus/seed. See the input layout at the top of fuzz_jsonfuse.c."""
import os

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "corpus", "seed")
INT64, FLOAT64, BOOL, STRING = 0, 1, 2, 3
NULLABLE = 4
N = {"a": 0, "b": 1, "id": 2, "s": 3, "n": 4, "x": 5, "event": 6, "amount": 7,
     "e_acute": 8, "proto": 9, "zero": 10, "123": 11, "quote": 12, "backslash": 13,
     "tab": 14, "ctl": 15, "empty": 16, "long": 17, "euro": 18, "emoji": 19}
W_STR, W_INT, W_FLOAT, W_BOOL, W_NULL = 0, 1, 2, 3, 4

def seed(name, fields, wheres, body, *, skip=False, cap=7, repeat=0, rd=0, wr=0, fault=0, fault_at=1, invalid_name=None):
    head = bytes([
        (1 if skip else 0) | cap << 1 | repeat << 4,
        (len(fields) - 1) | len(wheres) << 4,
        rd, wr, fault, fault_at,
    ])
    for n, t in fields:
        head += bytes([invalid_name if invalid_name is not None else N[n], t])
    for n, kind, sel in wheres:
        head += bytes([N[n], kind, sel])
    with open(os.path.join(OUT, name), "wb") as f:
        f.write(head + body)

# The "FD" layout (fuzz_jsonfuse.c, build_plan): 3 bytes per field, a mode byte after the header.
FORM_STR, FORM_TYPE, FORM_TD, FORM_DT, FORM_UNKNOWN, FORM_NOTYPE, FORM_BADTYPE, FORM_LIST = range(8)
# Indices into dflts[] in fuzz_jsonfuse.c, in its order.
D = {k: i for i, k in enumerate(
    "0 1 -1 -5 2^53 -2^53 2^53+1 -(2^53+1) 2^53+2 2^60 2^60+1 2^63-1024 2^63-1023 INT_MAX INT_MIN INT_MIN+1 "
    "0.0 -0.0 1.5 2.0 1e308 5e-324 0.1 1e21 2^63f nan inf -inf true false null "
    "empty free esc e_acute u2028 emoji nul xff surrogate overlong above ctl1024 quote1024 plain1024 over1025 array".split())}
UNKNOWN_DEFAULT, UNKNOWN_PATH, UNKNOWN_NULLABLE, UNKNOWN_INT_KEY = 0, 1 << 6, 2 << 6, 3 << 6
LIMIT = 1

def seed7(name, fields, wheres, body, *, mode=0, skip=False, cap=7, repeat=0, rd=0, wr=0, fault=0, fault_at=1):
    """fields: (name, type, form, default index or unknown-key selector)."""
    head = b"FD" + bytes([
        (1 if skip else 0) | cap << 1 | repeat << 4,
        (max(len(fields), 1) - 1) | len(wheres) << 4,
        rd, wr, fault, fault_at, mode,
    ])
    if not mode & LIMIT:
        for n, t, form, d in fields:
            head += bytes([N[n], t | form << 4, d])
    for n, kind, sel in wheres:
        head += bytes([N[n], kind, sel])
    with open(os.path.join(OUT, name), "wb") as f:
        f.write(head + body)

def seeds_defaults():
    seed7("defaults_defaults_each_type", [("id", INT64, FORM_STR, 0), ("n", INT64, FORM_TD, D["-5"]), ("amount", FLOAT64, FORM_TD, D["1.5"]),
                                    ("x", FLOAT64, FORM_DT, D["-0.0"]), ("b", BOOL, FORM_TD, D["false"]), ("s", STRING, FORM_TD, D["free"])], [],
          b'{"id":1}\n{"id":2,"n":7,"amount":3,"x":1e3,"b":true,"s":"paid"}\n{"id":3,"n":null}\n')
    seed7("defaults_defaults_nullable", [("id", INT64, FORM_TYPE, 0), ("n", INT64 + NULLABLE, FORM_TD, D["null"]),
                                   ("b", BOOL + NULLABLE, FORM_TD, D["true"]), ("s", STRING + NULLABLE, FORM_DT, D["esc"])], [],
          b'{"id":1}\n{"id":2,"n":null,"b":null,"s":null}\n{"id":3,"n":4,"b":false,"s":"t"}\n{"id":4,"s":5}\n')
    seed7("defaults_float64_int_defaults", [("a", FLOAT64, FORM_TD, D["2^53"]), ("b", FLOAT64, FORM_TD, D["INT_MIN"]), ("n", FLOAT64, FORM_TD, D["2^60"]),
                                      ("x", FLOAT64 + NULLABLE, FORM_TD, D["0"]), ("s", FLOAT64, FORM_TD, D["2^63-1024"]), ("id", FLOAT64, FORM_TD, D["-(2^53+1)"])], [],
          b'{"id":1}\n{}\n')
    for label, d in [("2^53+1", D["2^53+1"]), ("int_max", D["INT_MAX"]), ("2^63-1023", D["2^63-1023"]), ("nan", D["nan"])]:
        seed7("defaults_float64_default_invalid_" + label.replace("^", "").replace("+", "p"), [("a", FLOAT64, FORM_TD, d)], [], b'{}\n')
    seed7("defaults_default_wrong_kinds", [("a", INT64, FORM_TD, D["1.5"]), ("b", BOOL, FORM_TD, D["0"])], [], b'{}\n')
    seed7("defaults_default_bad_strings", [("s", STRING, FORM_TD, D["surrogate"]), ("x", STRING, FORM_TD, D["over1025"])], [], b'{}\n')
    seed7("defaults_default_null_not_nullable", [("a", INT64, FORM_TD, D["null"])], [], b'{}\n')
    seed7("defaults_default_array", [("a", STRING, FORM_TD, D["array"])], [], b'{}\n')
    for label, sel in [("default", UNKNOWN_DEFAULT), ("path", UNKNOWN_PATH), ("nullable", UNKNOWN_NULLABLE), ("int_key", UNKNOWN_INT_KEY)]:
        seed7("defaults_unknown_key_" + label, [("id", INT64, FORM_STR, 0), ("a", INT64, FORM_UNKNOWN, sel)], [], b'{"id":1,"a":1}\n')
    seed7("defaults_no_type", [("a", INT64, FORM_NOTYPE, D["1"])], [], b'{"a":1}\n')
    for label, sel in [("int", 0), ("null", 1), ("true", 2), ("array", 3)]:
        seed7("defaults_type_not_string_" + label, [("a", INT64, FORM_BADTYPE, sel)], [], b'{"a":1}\n')
    seed7("defaults_list_form", [("a", INT64, FORM_LIST, 0)], [], b'{"a":1}\n')
    seed7("defaults_bad_type_with_default", [("a", 0x80, FORM_TD, D["1"])], [], b'{"a":1}\n')
    seed7("defaults_string_defaults_max", [("s", STRING, FORM_TD, D["ctl1024"]), ("x", STRING, FORM_TD, D["quote1024"]), ("n", STRING, FORM_DT, D["plain1024"])], [],
          b'{}\n{"s":"v"}\n{}\n', cap=1)
    seed7("defaults_default_where_same_key", [("a", INT64, FORM_TD, D["1"]), ("id", INT64, FORM_STR, 0)], [("a", W_INT, 1)],
          b'{"id":1}\n{"id":2,"a":1}\n{"id":3,"a":2}\n')
    KEY_AS_STRING = 8
    seed7("defaults_dup_name_array_specs", [("zero", STRING, FORM_TD, D["ctl1024"]), ("zero", STRING | KEY_AS_STRING, FORM_TD, D["ctl1024"])], [],
          b'{"0":"' + b"y" * 70000 + b'"}\n')
    for v in range(4):
        seed7("defaults_limit_variant%d" % v, [], [], b'{}\n{"f064":"v"}\n{"f000":null,"f127":1}\n{}\n', mode=LIMIT | v << 1, cap=1 if v == 0 else 7)
    seed7("defaults_limit_low_memory", [], [], b'{}\n{}\n', mode=LIMIT, cap=1, fault=15, fault_at=60)
    seed7("defaults_limit_write_fail", [], [], b'{}\n{}\n[]\n', mode=LIMIT, cap=1, fault=5, fault_at=2)
    seed7("defaults_limit_short_io", [], [], b'{}\n{}\n', mode=LIMIT | 1 << 1, cap=7, rd=1, wr=2)

# The "FP" layout: the FD layout with 5 bytes per field, the last two a path byte and a path seed (build_path).
SEGS = ["a", "b", "c", "id", "x.y", "0", "", "\u00e9"]
def PATH(depth, ps, kind=0):
    return 0x80 | kind << 3 | (depth - 1), ps

def seed8(name, fields, wheres, body, *, mode=0, skip=False, cap=7, repeat=0, rd=0, wr=0, fault=0, fault_at=1):
    """fields: (name, type, form, default index, (path byte, path seed) or None)."""
    head = b"FP" + bytes([
        (1 if skip else 0) | cap << 1 | repeat << 4,
        (max(len(fields), 1) - 1) | len(wheres) << 4,
        rd, wr, fault, fault_at, mode,
    ])
    if not mode & LIMIT:
        for n, t, form, d, path in fields:
            pb, ps = path if path is not None else (0, 0)
            head += bytes([N[n], t | form << 4, d, pb, ps])
    for n, kind, sel in wheres:
        head += bytes([N[n], kind, sel])
    with open(os.path.join(OUT, name), "wb") as f:
        f.write(head + body)

def seeds_paths():
    ab, ac = PATH(2, 0 | 1 << 3), PATH(2, 0 | 2 << 3)
    seed8("paths_nested", [("id", INT64, FORM_STR, 0, None), ("x", INT64, FORM_TYPE, 0, ab)], [],
          b'{"id":1,"a":{"b":2}}\n{"id":2,"a":{"b":-3,"z":1},"b":4}\n{"id":3,"a":{"\\u0062":5}}\n')
    seed8("paths_deep8", [("x", INT64, FORM_TYPE, 0, PATH(8, 0))], [],
          '{"a":{"a":{"a":{"id":{"x.y":{"0":{"":{"\u00e9":5}}}}}}}}\n{"a":{"a":{"a":{"id":{"x.y":{"0":{"":{}}}}}}}}\n'.encode())
    seed8("paths_deep8_leaf_object", [("x", INT64, FORM_TYPE, 0, PATH(8, 0))], [],
          '{"a":{"a":{"a":{"id":{"x.y":{"0":{"":{"\u00e9":{"a":{"a":{"a":1}}}}}}}}}}}\n'.encode())
    seed8("paths_not_object_default", [("x", INT64, FORM_TD, D["-5"], ab)], [],
          b'{"a":{"b":1}}\n{"a":{}}\n{}\n{"a":[1]}\n{"a":"s"}\n{"a":1}\n{"a":true}\n{"a":null}\n')
    seed8("paths_null_intermediate", [("x", STRING + NULLABLE, FORM_TD, D["null"], ab)], [], b'{"a":{"b":null}}\n{"a":null}\n')
    seed8("paths_duplicates", [("id", INT64, FORM_STR, 0, None), ("x", INT64, FORM_TYPE, 0, ab), ("n", INT64, FORM_TYPE, 0, ac)], [],
          b'{"id":1,"a":{"b":1,"c":2,"z":1,"z":2},"q":{"b":1,"b":2}}\n{"id":2,"a":{"b":1,"c":2,"c":3}}\n{"a":{"b":1,"c":2},"a":{}}\n')
    seed8("paths_dup_order", [("id", INT64, FORM_STR, 0, None), ("x", INT64, FORM_TYPE, 0, ab)], [],
          b'{"id":1,"a":{"b":1,"b":2},"id":2}\n')
    seed8("paths_equal_paths", [("x", INT64, FORM_TYPE, 0, ab), ("n", INT64, FORM_TYPE, 0, ab)], [], b'{"a":{"b":1}}\n')
    seed8("paths_name_equals_path", [("a", INT64, FORM_STR, 0, None), ("n", INT64, FORM_TYPE, 0, PATH(1, 0))], [], b'{"a":1}\n')
    seed8("paths_prefix_shorter_first", [("x", INT64, FORM_TYPE, 0, PATH(1, 0)), ("n", INT64, FORM_TYPE, 0, ab)], [], b'{"a":{"b":1}}\n')
    seed8("paths_prefix_longer_first", [("x", INT64, FORM_TYPE, 0, PATH(3, 0 | 1 << 3)), ("n", INT64, FORM_TYPE, 0, ab)], [], b'{}\n')
    seed8("paths_where_over_field_path", [("x", INT64, FORM_TYPE, 0, ab)], [("a", W_INT, 1)], b'{"a":{"b":1}}\n')
    seed8("paths_where_shares_path", [("x", INT64, FORM_TD, D["1"], PATH(1, 0))], [("a", W_INT, 1)],
          b'{"a":1}\n{"a":2}\n{}\n{"a":{}}\n')
    seed8("paths_siblings_and_swaps", [("x", INT64, FORM_TYPE, 0, ab), ("n", INT64, FORM_TYPE, 0, ac), ("s", INT64, FORM_TYPE, 0, PATH(2, 1 | 0 << 3)),
                                    ("b", INT64, FORM_STR, 0, None)], [],
          b'{"a":{"b":1,"c":2},"b":{"a":3}}\n{"b":5,"a":{"c":2,"b":1}}\n')
    for v in range(8):
        seed8("paths_invalid_shape_%d" % v, [("id", INT64, FORM_STR, 0, None), ("x", INT64, FORM_TD, D["1"], PATH(3, v | 1 << 3, 6))], [], b'{"id":1}\n')
    seed8("paths_segment_not_utf8", [("x", INT64, FORM_TYPE, 0, PATH(2, 1, 7))], [], b'{}\n')
    seed8("paths_segment_1025", [("x", INT64, FORM_TYPE, 0, PATH(2, 0, 7))], [], b'{}\n')
    seed8("paths_path_before_default", [("x", INT64, FORM_TD, D["1.5"], PATH(2, 2, 6))], [], b'{}\n')
    seed8("paths_bad_type_before_path", [("x", 0x80, FORM_TD, D["1"], PATH(2, 2, 6))], [], b'{}\n')
    for v in range(4):
        seed8("paths_limit_variant%d" % v, [], [], b'{}\n{"f064":1}\n', mode=LIMIT | v << 1, cap=7)
    for m in range(1, 4):
        # rd and wr form the limit record's seed: mutation m at level 3 of field 37.
        sd = m | 3 << 2 | 37 << 5
        seed8("paths_limit_mutation%d" % m, [], [], b'{}\n', mode=LIMIT | 1 << 1, cap=7, rd=sd >> 8, wr=sd & 0xFF)
    seed8("paths_limit_where_prefix", [], [("a", W_INT, 1)], b'{}\n', mode=LIMIT | 1 << 1, cap=7)
    seed8("paths_limit_short_io_faults", [], [], b'{}\n{}\n', mode=LIMIT | 2 << 1, cap=7, rd=1, wr=2, fault=5, fault_at=2)

# The "FW" layout: the FP fields, then 10 bytes per where entry (build_where9), and two record-seed bytes.
# The operand pools of fuzz_jsonfuse.c, in their order: wv_num, wv_str, wv_other, wv_bad.
NUM = ("0 1 -1 5 -5 123 2^53 2^53+1 -(2^53+1) 2^53+2 2^63-1024 INT_MAX-1 INT_MAX INT_MIN INT_MIN+1 "
       "0.0 -0.0 0.5 -0.5 1.0 1.5 5.0 123.0 0.1 2^53f 2^53+2f 2^63-1024f 2^63f -2^63f -2^63-2048f 1e19 2^64-2048f 2^64f "
       "5e-324 -5e-324 1e308 -1e308").split()
STR = ("empty a aa ab abc b s x7f a_x7f a_c280 c280 e_acute e_acute_a euro uffff emoji purchase tsZ ts.5Z x_nl_y "
       "quote backslash a_x01 a_nul_b s1023 s1024 s1023t e512").split()
OTHER = "true false null".split()
BAD = "nan inf -inf xff xc3 surrogate overlong above s1025 array".split()
assert (len(NUM), len(STR), len(OTHER), len(BAD)) == (37, 28, 3, 10)

def num(label): return NUM.index(label)                     # S_NUM selector
def st(label): return STR.index(label)                      # S_STR selector
def sc(label):                                              # S_SCALAR selector
    if label in NUM: return NUM.index(label)
    if label in STR: return len(NUM) + STR.index(label)
    return len(NUM) + len(STR) + OTHER.index(label)
def bad(label): return BAD.index(label)                     # S_BAD_SCALAR selector
def badb(label):                                            # S_BAD_BOUND selector
    return OTHER.index(label) if label in OTHER else len(OTHER) + BAD.index(label)

(K_SHORT, K_EQ, K_NUMR, K_STRR, K_IN, K_EQ_NUMR, K_EQ_STRR, K_IN_NUMR, K_IN_STRR, K_EQ_IN,
 K_ALL_NUM, K_ALL_STR, K_MIXED, K_NOOP, K_BAD, K_BREAK) = range(16)
M_ANY, M_NUM, M_STR, M_FEW = range(4)
IN_COUNTS = [1, 2, 3, 5, 16, 255, 256, 128]
RANGE_NAMES = [">", ">=", "<", "<="]
OWN_NAME = 0x80

def perm_for(ranges):
    """The order index (b3 bits 0-4) whose first operators are `ranges` (build_where9's factorial base)."""
    for perm in range(24):
        order, p = list(range(4)), perm
        for k in range(4):
            radix = 4 - k
            j = k + p % radix
            p //= radix
            order[k], order[j] = order[j], order[k]
        if [RANGE_NAMES[o] for o in order[:len(ranges)]] == list(ranges):
            return perm
    raise ValueError(ranges)

def W(name, kind, sels=(), ranges=(), *, sub=0, msrc=M_ANY, count=1, pos=0, flip=False, path=None,
      path_last=False, own_leaf=False, shuffle=False, key_as_string=False, mixed_in=0):
    """One where entry. sels: operand selectors in declared order; ranges: the range operators in order.
    mixed_in (K_MIXED only): the element count of an in after the bounds, 257 included; 0 for none."""
    b0 = name if isinstance(name, int) else N[name]
    b1 = kind | (0x10 if path_last else 0) | (0x20 if own_leaf else 0) | (0x40 if shuffle else 0) | (0x80 if path else 0)
    b2 = (max(len(ranges), 1) - 1) | (sub if kind >= K_NOOP else msrc) << 2 | IN_COUNTS.index(count) << 5
    if mixed_in:
        assert kind == K_MIXED and msrc == M_ANY and count == 1
        b2 |= 0x10 | (0x04 if mixed_in == 257 else IN_COUNTS.index(mixed_in) << 5)
    b3 = (perm_for(ranges) if ranges else 0) | pos << 5 | (0x80 if flip else 0)
    pb, ps = path if path else (0, 0)
    sels = list(sels) + [0] * (4 - len(sels))
    return bytes([b0, b1, b2, b3, pb | (0x40 if key_as_string else 0), ps] + sels)

def seed9(name, fields, wheres, body, *, mode=0, records=0, forced=False, rec_seed=0, skip=False, cap=3, repeat=0,
          rd=0, wr=0, fault=0, fault_at=1):
    """fields as for seed8; wheres: W(...) entries; cap indexes the four largest caps (3: 1 MiB)."""
    head = b"FW" + bytes([
        (1 if skip else 0) | cap << 1 | repeat << 4,
        (max(len(fields), 1) - 1) | records << 4 | (0x80 if forced else 0),
        rd, wr, fault, fault_at, mode | (0 if mode & LIMIT else len(wheres) << 3), rec_seed >> 8, rec_seed & 0xFF,
    ])
    if not mode & LIMIT:
        for n, t, form, d, path in fields:
            pb, ps = path if path is not None else (0, 0)
            head += bytes([N[n], t | form << 4, d, pb, ps])
        for w in wheres:
            head += w
    with open(os.path.join(OUT, name), "wb") as f:
        f.write(head + body)

def seeds_where_entries():
    ID = [("id", INT64, FORM_STR, 0, None)]
    ab, ac, a1 = PATH(2, 0 | 1 << 3), PATH(2, 0 | 2 << 3), PATH(1, 0)
    # = and the shorthand: type-strict equality.
    seed9("where_entry_eq_kinds", ID, [W("s", K_EQ, [sc("purchase")]), W("n", K_SHORT, [sc("5")]), W("x", K_EQ, [sc("0.0")]),
                              W("b", K_SHORT, [sc("true")]), W("event", K_EQ, [sc("null")])],
          b'{"id":1,"s":"purchase","n":5,"x":-0.0,"b":true,"event":null}\n{"id":2,"s":"purchase","n":5.0,"x":0.0,"b":true,"event":null}\n'
          b'{"id":3,"s":"purchase","n":5,"x":0,"b":true,"event":null}\n{"id":4,"s":"purchas","n":5,"x":0.0,"b":true,"event":null}\n'
          b'{"id":5,"s":"purch\\u0061se","n":5,"x":0.0,"b":1,"event":null}\n{"id":6,"s":"purchase","n":5,"x":0.0,"b":true,"event":false}\n')
    seed9("where_entry_eq_big_ints", ID, [W("n", K_EQ, [sc("INT_MAX")]), W("x", K_SHORT, [sc("INT_MIN")])],
          b'{"id":1,"n":9223372036854775807,"x":-9223372036854775808}\n{"id":2,"n":9223372036854775808,"x":-9223372036854775808}\n'
          b'{"id":3,"n":9.223372036854775807e18,"x":-9223372036854775808}\n{"id":4,"n":9223372036854775807,"x":-9223372036854775809}\n')
    # in: 1 to 256 elements, duplicates, by the = rule.
    seed9("where_entry_in_small", ID, [W("n", K_IN, [sc("5")], count=2, msrc=M_ANY), W("s", K_IN, [sc("a")], count=3, msrc=M_FEW)],
          b'{"id":1,"n":5,"s":"a"}\n{"id":2,"n":5.0,"s":"a"}\n{"id":3,"n":"5","s":"a"}\n{"id":4,"n":5,"s":"\\u0061"}\n{"id":5,"s":"a"}\n')
    for count in (16, 255, 256, 128):
        seed9("where_entry_in_%d" % count, ID, [W("n", K_IN, [sc("2^53+1")], count=count, msrc=M_NUM)],
              b'{"id":1,"n":9007199254740993}\n{"id":2,"n":9007199254740992}\n{"id":3,"n":9007199254740993.0}\n{"id":4,"n":-0.0}\n')
    seed9("where_entry_in_strings_256", ID, [W("s", K_IN, [st("e_acute")], count=256, msrc=M_STR)],
          '{"id":1,"s":"\u00e9"}\n{"id":2,"s":"\\u00e9"}\n{"id":3,"s":"\\u00E9a"}\n{"id":4,"s":"s"}\n'.encode())
    # Number ranges: exact int-against-real comparison, never through a double.
    seed9("where_entry_range_int_vs_real", ID, [W("a", K_NUMR, [num("2^53f")], [">"]), W("b", K_NUMR, [num("2^53+1")], ["<"])],
          b'{"id":1,"a":9007199254740993,"b":9007199254740992.0}\n{"id":2,"a":9007199254740992,"b":9007199254740992.0}\n'
          b'{"id":3,"a":9007199254740993,"b":9007199254740993.0}\n{"id":4,"a":9007199254740994,"b":9007199254740991}\n')
    seed9("where_entry_range_2p63", ID, [W("a", K_NUMR, [num("2^63f")], [">="]), W("b", K_NUMR, [num("INT_MAX")], ["<"])],
          b'{"id":1,"a":9223372036854775808,"b":9.2233720368547758e18}\n{"id":2,"a":9223372036854775807,"b":9223372036854775806}\n'
          b'{"id":3,"a":18446744073709551615,"b":-9223372036854775808}\n{"id":4,"a":9.223372036854775808e18,"b":1e19}\n')
    seed9("where_entry_range_2p64", ID, [W("a", K_NUMR, [num("2^64f")], ["<"]), W("b", K_NUMR, [num("2^64-2048f")], ["<="])],
          b'{"id":1,"a":18446744073709551615,"b":18446744073709549568}\n{"id":2,"a":18446744073709551615,"b":18446744073709549569}\n'
          b'{"id":3,"a":1.8446744073709552e19,"b":1}\n{"id":4,"a":18446744073709551616,"b":1}\n')
    seed9("where_entry_range_int_min", ID, [W("a", K_NUMR, [num("-2^63f")], [">="]), W("b", K_NUMR, [num("-2^63-2048f")], [">"])],
          b'{"id":1,"a":-9223372036854775808,"b":-9223372036854775808}\n{"id":2,"a":-9223372036854775807,"b":-9223372036854775807}\n'
          b'{"id":3,"a":-9223372036854775809,"b":-9223372036854775808}\n{"id":4,"a":-9.2233720368547758e18,"b":-9.223372036854777856e18}\n')
    seed9("where_entry_range_zero_tiny_huge", ID, [W("a", K_NUMR, [num("0"), num("0")], [">=", "<="]), W("b", K_NUMR, [num("5e-324")], ["<"]),
                                          W("x", K_NUMR, [num("-1e308")], [">"])],
          b'{"id":1,"a":-0.0,"b":0,"x":1e308}\n{"id":2,"a":0,"b":0,"x":9223372036854775807}\n{"id":3,"a":0.0,"b":-5e-324,"x":-1e308}\n'
          b'{"id":4,"a":1e-400,"b":1e-400,"x":1}\n{"id":5,"a":-0,"b":-1,"x":1e300}\n')
    seed9("where_entry_range_bounds_equal", ID, [W("a", K_NUMR, [num("5"), num("5.0")], [">=", "<="]), W("b", K_NUMR, [num("1.5"), num("1.5")], [">=", "<="])],
          b'{"id":1,"a":5,"b":1.5}\n{"id":2,"a":5.0,"b":1.6}\n{"id":3,"a":5,"b":2}\n{"id":4,"a":4.999999999999999,"b":1}\n')
    seed9("where_entry_range_raw_never", ID, [W("a", K_NUMR, [num("0")], [">"]), W("b", K_NUMR, [num("0")], ["<"])],
          b'{"id":1,"a":1,"b":-1}\n{"id":2,"a":18446744073709551616,"b":-1}\n{"id":3,"a":1e400,"b":-1}\n{"id":4,"a":1,"b":-1e400}\n'
          b'{"id":5,"a":123456789012345678901234567890,"b":-9223372036854775809}\n{"id":6,"a":"1","b":-1}\n{"id":7,"a":true,"b":-1}\n')
    seed9("where_entry_range_two_ops", ID, [W("a", K_NUMR, [num("0"), num("123")], [">", "<"])],
          b'{"id":1,"a":200}\n{"id":2,"a":5}\n{"id":3,"a":-5}\n{"id":4,"a":123}\n{"id":5,"a":9.5}\n')
    seed9("where_entry_range_four_ops", ID, [W("a", K_NUMR, [num("-1"), num("123"), num("0.0"), num("123.0")], [">", "<", ">=", "<="])],
          b'{"id":1,"a":0}\n{"id":2,"a":123}\n{"id":3,"a":-0.0}\n{"id":4,"a":122.99999999999999}\n')
    seed9("where_entry_contradictory", ID, [W("a", K_EQ_NUMR, [num("1"), num("5")], [">"]), W("b", K_NUMR, [num("5"), num("1")], [">", "<"])],
          b'{"id":1,"a":1,"b":3}\n{"id":2,"a":6,"b":6}\n{"id":3,"a":1,"b":0}\n')
    # String ranges: unescaped bytes, unsigned, the shorter first on a common prefix.
    seed9("where_entry_string_unsigned", ID, [W("s", K_STRR, [st("a_x7f")], ["<"]), W("x", K_STRR, [st("b")], ["<"])],
          b'{"id":1,"s":"a\\u0080","x":"aa"}\n{"id":2,"s":"a","x":"aa"}\n{"id":3,"s":"a\\u007e","x":"ba"}\n{"id":4,"s":"a\xc2\x80","x":"a"}\n')
    seed9("where_entry_string_prefixes", ID, [W("s", K_STRR, [st("ab"), st("abc")], [">=", "<"]), W("x", K_STRR, [st("empty")], [">"])],
          b'{"id":1,"s":"ab","x":"a"}\n{"id":2,"s":"abb","x":""}\n{"id":3,"s":"abc","x":"\\u0000"}\n{"id":4,"s":"a","x":"b"}\n{"id":5,"s":"ab\\u0000","x":"b"}\n')
    seed9("where_entry_string_unicode_order", ID, [W("s", K_STRR, [st("uffff"), st("emoji")], [">", "<"]), W("x", K_STRR, [st("e_acute")], [">="])],
          '{"id":1,"s":"\U0001F600","x":"\u00e9"}\n{"id":2,"s":"\\ud83d\\ude00","x":"\\u00e9a"}\n{"id":3,"s":"\\ud800\\udc00","x":"\\u00e9"}\n'
          '{"id":4,"s":"\\uffff","x":"e"}\n{"id":5,"s":"\\ud83c\\udfff","x":"\u20ac"}\n'.encode())
    seed9("where_entry_string_1024", ID, [W("s", K_STRR, [st("s1024")], ["<"]), W("x", K_STRR, [st("s1023")], [">"])],
          b'{"id":1,"s":"' + b"s" * 1023 + b'","x":"' + b"s" * 1024 + b'"}\n{"id":2,"s":"' + b"s" * 1025 + b'","x":"' + b"s" * 1024 + b'"}\n'
          b'{"id":3,"s":"' + b"s" * 1023 + b'r","x":"' + b"s" * 1023 + b'"}\n')
    seed9("where_entry_string_timestamps", ID, [W("s", K_STRR, [st("tsZ"), st("ts.5Z")], ["<", ">"])],
          b'{"id":1,"s":"2026-11-09T10:00:00.6Z"}\n{"id":2,"s":"2026-11-09T10:00:00.1Z"}\n{"id":3,"s":"2026-11-09T10:00:00+02:00"}\n'
          b'{"id":4,"s":"2026-11-09T10:00:00Z"}\n{"id":5,"s":"2026-11-09T10:00:00.5Z"}\n')
    seed9("where_entry_string_vs_number", ID, [W("s", K_STRR, [st("empty")], [">="]), W("n", K_NUMR, [num("0")], [">="])],
          b'{"id":1,"s":"","n":0}\n{"id":2,"s":0,"n":0}\n{"id":3,"s":"a","n":"a"}\n{"id":4,"s":null,"n":null}\n')
    # Operators combined: = with ranges, in with ranges, all six.
    seed9("where_entry_eq_and_range", ID, [W("a", K_EQ_NUMR, [num("5"), num("5.0")], [">="]), W("s", K_EQ_STRR, [st("b"), st("a")], [">"])],
          b'{"id":1,"a":5,"s":"b"}\n{"id":2,"a":5.0,"s":"b"}\n{"id":3,"a":5,"s":"a"}\n')
    seed9("where_entry_in_and_range", ID, [W("a", K_IN_NUMR, [num("2^53+1"), num("2^53f")], [">"], count=2), W("s", K_IN_STRR, [st("ab"), st("ab")], ["<="], count=1)],
          b'{"id":1,"a":9007199254740993,"s":"ab"}\n{"id":2,"a":9007199254740992,"s":"ab"}\n{"id":3,"a":9007199254740993,"s":"a"}\n')
    seed9("where_entry_eq_and_in", ID, [W("a", K_EQ_IN, [sc("null"), sc("null")], count=1)], b'{"id":1,"a":null}\n{"id":2,"a":0}\n{"id":3}\n')
    # The fifth and sixth operators take the first two selectors again: >= 5 and <= 5 here.
    seed9("where_entry_all_six_numbers", ID, [W("a", K_ALL_NUM, [num("5"), num("5"), num("0"), num("123")], [">", "<", ">=", "<="], count=255)],
          b'{"id":1,"a":5}\n{"id":2,"a":6}\n{"id":3,"a":5.0}\n{"id":4,"a":4}\n')
    seed9("where_entry_all_six_strings", ID, [W("s", K_ALL_STR, [st("b"), st("b"), st("a"), st("c280")], [">", "<", ">=", "<="], count=3)],
          b'{"id":1,"s":"b"}\n{"id":2,"s":"ba"}\n{"id":3,"s":"\\u0062"}\n')
    seed9("where_entry_all_six_shuffled", ID, [W("s", K_ALL_STR, [st("b"), st("b"), st("a"), st("c280")], [">", "<", ">=", "<="], count=3, shuffle=True),
                                      W("a", K_ALL_NUM, [num("5"), num("5"), num("0"), num("123")], [">", "<", ">=", "<="], count=2, shuffle=True)],
          b'{"id":1,"s":"b","a":5}\n{"id":2,"s":"ba","a":5}\n')
    # Where paths: nested leaves, non-object and null intermediates, missing keys, duplicates.
    seed9("where_entry_path_nested", ID, [W("x", K_EQ, [sc("1")], path=ab), W("n", K_NUMR, [num("0")], [">"], path=ac)],
          b'{"id":1,"a":{"b":1,"c":2}}\n{"id":2,"a":{"b":1,"c":0}}\n{"id":3,"a":{"\\u0062":1,"c":1}}\n{"id":4,"a":{"c":1}}\n{"id":5}\n')
    for label, rec in [("array", b'[1]'), ("string", b'"s"'), ("number", b'5'), ("null", b'null'), ("bool", b'true')]:
        seed9("where_entry_path_not_object_" + label, ID, [W("x", K_EQ, [sc("1")], path=ab)],
              b'{"id":1,"a":{"b":1}}\n{"id":2,"a":{}}\n{"id":3,"a":' + rec + b'}\n{"id":4,"a":{"b":1}}\n')
    seed9("where_entry_path_not_object_second", ID, [W("x", K_EQ, [sc("1")]), W("n", K_EQ, [sc("1")], path=ab)],
          b'{"id":1,"x":1,"a":{"b":1}}\n{"id":2,"x":1,"a":5}\n')
    seed9("where_entry_where_not_scalar_first", ID, [W("x", K_EQ, [sc("1")]), W("n", K_EQ, [sc("1")], path=ab)],
          b'{"id":1,"x":1,"a":{"b":1}}\n{"id":2,"x":[1],"a":5}\n')
    seed9("where_entry_path_leaf_container", ID, [W("x", K_NUMR, [num("0")], [">"], path=PATH(3, 0 | 1 << 3 | 2 << 6))],
          b'{"id":1,"a":{"b":{"c":1}}}\n{"id":2,"a":{"b":{"c":{}}}}\n')
    seed9("where_entry_path_deep8", ID, [W("x", K_STRR, [st("e_acute")], [">="], path=PATH(8, 0))],
          '{"id":1,"a":{"a":{"a":{"id":{"x.y":{"0":{"":{"\u00e9":"\u00e9"}}}}}}}}\n{"id":2,"a":{"a":{"a":{"id":{"x.y":{"0":{"":{"\\u00e9":"e"}}}}}}}}\n'.encode())
    seed9("where_entry_path_duplicates", ID + [("s", INT64, FORM_TYPE, 0, ac)], [W("x", K_EQ, [sc("1")], path=ab)],
          b'{"id":1,"a":{"b":1,"c":2,"z":1,"z":2}}\n{"id":2,"a":{"b":1,"c":2,"\\u0062":1}}\n{"id":3,"a":{"c":2,"b":1},"a":{}}\n')
    seed9("where_entry_path_dup_where_owner", ID, [W("x", K_EQ, [sc("1")], path=ab), W("n", K_EQ, [sc("1")], path=ac)],
          b'{"id":1,"a":{"b":1,"c":1}}\n{"id":2,"a":{"b":1,"c":1,"c":2}}\n')
    seed9("where_entry_where_equals_field_path", [("x", INT64, FORM_TYPE, 0, ab)], [W(OWN_NAME, K_NUMR, [num("0")], [">"], path=ab)],
          b'{"a":{"b":1}}\n{"a":{"b":-1}}\n{"a":{"b":"s"}}\n')
    seed9("where_entry_where_no_defaults", [("id", INT64, FORM_STR, 0, None), ("x", INT64, FORM_TD, D["1"], ab)], [W("n", K_EQ, [sc("1")], path=ab)],
          b'{"id":1}\n{"id":2,"a":{}}\n{"id":3,"a":{"b":1}}\n')
    seed9("where_entry_own_names_and_segments", ID, [W(OWN_NAME, K_EQ, [sc("1")]), W(OWN_NAME, K_EQ, [sc("5")], path=ab, own_leaf=True)],
          b'{"id":1,"w0":1,"a":{"v1":5}}\n{"id":2,"w0":1,"a":{"b":5}}\n')
    # Every plan failure a where entry can raise, in compile order.
    seed9("where_entry_unknown_keys", ID, [W("a", K_NOOP, [sc("1")], sub=2)], b'{"id":1}\n')
    for sub, label in [(3, "IN"), (4, "int_key"), (5, "Path"), (6, "space_gt")]:
        seed9("where_entry_unknown_key_" + label, ID, [W("a", K_EQ, [sc("1")]), W("b", K_NOOP, [num("1")], sub=sub, pos=1)], b'{"id":1}\n')
    seed9("where_entry_unknown_before_bad_path", ID, [W("a", K_NOOP, [bad("nan")], sub=7, path=PATH(2, 2, 6), path_last=True)], b'{"id":1}\n')
    seed9("where_entry_no_operator", ID, [W("a", K_NOOP, sub=0)], b'{"id":1}\n')
    seed9("where_entry_only_path", ID, [W("a", K_NOOP, sub=1, path=ab)], b'{"id":1}\n')
    for v in range(8):
        seed9("where_entry_path_invalid_shape_%d" % v, ID, [W("a", K_BAD, [bad("nan")], sub=1, path=PATH(3, v | 1 << 3, 6), path_last=True)], b'{"id":1}\n')
    seed9("where_entry_path_segment_not_utf8", ID, [W("a", K_EQ, [sc("1")], path=PATH(2, 1, 7))], b'{"id":1}\n')
    seed9("where_entry_path_segment_1025", ID, [W("a", K_BAD, [bad("nan")], sub=1, path=PATH(2, 0, 7))], b'{"id":1}\n')
    for k, label in enumerate(["xff", "e2_82", "surrogate", "f5", "overlong"]):
        seed9("where_entry_invalid_name_" + label, ID, [W("a", K_EQ, [sc("1")]), W(0xFB + k, K_EQ, [sc("1")])], b'{"id":1}\n')
    for label in ["nan", "inf", "-inf", "xff", "xc3", "surrogate", "overlong", "above", "s1025"]:
        seed9("where_entry_shorthand_invalid_" + label, ID, [W("a", K_BAD, [bad(label)], sub=0)], b'{"id":1}\n')
    for label in ["nan", "-inf", "s1025", "array", "xc3"]:
        seed9("where_entry_eq_invalid_" + label, ID, [W("a", K_BAD, [bad(label)], sub=1)], b'{"id":1}\n')
    for label in ["true", "null", "nan", "inf", "array", "xff", "s1025"]:
        seed9("where_entry_bound_invalid_" + label, ID, [W("a", K_BAD, [num("1"), badb(label)], [">", "<="], sub=2, pos=1)], b'{"id":1}\n')
    seed9("where_entry_bound_invalid_first", ID, [W("a", K_BAD, [badb("false"), st("a")], ["<", ">"], sub=2, pos=0, flip=True)], b'{"id":1}\n')
    seed9("where_entry_in_not_array", ID, [W("a", K_BAD, [sc("5")], sub=3)], b'{"id":1}\n')
    seed9("where_entry_in_empty", ID, [W("a", K_BAD, sub=4)], b'{"id":1}\n')
    seed9("where_entry_in_string_key", ID, [W("a", K_BAD, [sc("1")], sub=5, count=3)], b'{"id":1}\n')
    seed9("where_entry_in_hole", ID, [W("a", K_BAD, [sc("1")], sub=5, count=2, flip=True)], b'{"id":1}\n')
    seed9("where_entry_in_257", ID, [W("a", K_BAD, [sc("1")], sub=6)], b'{"id":1}\n')
    seed9("where_entry_in_257_bad_member", ID, [W("a", K_BAD, [bad("nan")], sub=6, flip=True)], b'{"id":1}\n')
    seed9("where_entry_in_257_after_bad_eq", ID, [W("a", K_BAD, [bad("inf"), sc("1")], sub=6, pos=1)], b'{"id":1}\n')
    seed9("where_entry_in_257_before_bad_eq", ID, [W("a", K_BAD, [sc("1"), bad("inf")], sub=6, pos=2)], b'{"id":1}\n')
    seed9("where_entry_in_257_after_range", ID, [W("a", K_BAD, [num("1"), sc("1")], [">"], sub=6, pos=3)], b'{"id":1}\n')
    for count in (1, 16, 256):
        seed9("where_entry_in_bad_member_of_%d" % count, ID, [W("a", K_BAD, [sc("1")], sub=7, count=count)], b'{"id":1}\n')
    seed9("where_entry_one_kind", ID, [W("a", K_MIXED, [num("1"), st("b")], [">", "<"])], b'{"id":1,"a":1}\n')
    seed9("where_entry_one_kind_late", ID, [W("a", K_MIXED, [st("a"), st("b"), num("5"), st("c280")], [">=", "<", ">", "<="], pos=1, flip=True)], b'{"id":1}\n')
    # The one-kind rule comes after every operand, so a 257-element in wins over it.
    for count, shuffle in [(257, False), (256, False), (257, True)]:
        seed9("where_entry_one_kind_in_%d%s" % (count, "_shuffled" if shuffle else ""), ID,
              [W("a", K_MIXED, [num("1"), st("b"), sc("1")], [">", "<"], mixed_in=count, shuffle=shuffle)], b'{"id":1,"a":1}\n')
    seed9("where_entry_one_kind_not_eq_in", ID, [W("a", K_BREAK, [num("1"), num("1"), st("b")], [">", "<"], sub=0, pos=2, count=2)],
          b'{"id":1,"a":1}\n{"id":2,"a":"b"}\n')
    for n, pos, sub in [(5, 1, 0), (5, 1, 1), (4, 1, 2), (3, 1, 3), (2, 1, 4), (5, 0, 0), (5, 3, 1), (4, 3, 0)]:
        seed9("where_entry_break_n%d_pos%d_sub%d" % (n, pos, sub), ID,
              [W("a", K_BREAK, [num("1"), num("5"), num("0"), num("123")], [">", "<", ">=", "<="][:n - 1], sub=sub, pos=pos, count=IN_COUNTS[n - 2])],
              b'{"id":1,"a":1}\n')
    seed9("where_entry_dup_name_123", ID, [W("123", K_EQ, [sc("1")]), W("123", K_EQ, [sc("1")], key_as_string=True)], b'{"id":1,"123":1}\n')
    seed9("where_entry_dup_where_paths", ID, [W("a", K_EQ, [sc("1")], path=ab), W("b", K_EQ, [sc("1")], path=ab)], b'{"id":1}\n')
    seed9("where_entry_where_prefix_of_field", [("x", INT64, FORM_TYPE, 0, ab)], [W("n", K_EQ, [sc("1")], path=a1)], b'{"a":{"b":1}}\n')
    seed9("where_entry_field_prefix_of_where", [("x", INT64, FORM_TYPE, 0, a1)], [W("n", K_EQ, [sc("1")], path=ab)], b'{"a":1}\n')
    seed9("where_entry_where_prefix_of_where", ID, [W("n", K_EQ, [sc("1")], path=PATH(3, 0 | 1 << 3)), W("x", K_EQ, [sc("1")], path=ab)], b'{"id":1}\n')
    seed9("where_entry_name_is_prefix", ID, [W("a", K_EQ, [sc("1")]), W("x", K_EQ, [sc("1")], path=ab)], b'{"id":1}\n')
    many = [W(OWN_NAME, K_NUMR, [num("-1")], [">"]) for _ in range(16)]
    seed9("where_entry_sixteen_entries", ID, many, b'{"id":1,' + b",".join(b'"w%x":%d' % (i, i) for i in range(16)) + b'}\n')
    seed9("where_entry_seventeen_entries", ID, many + [W(OWN_NAME, K_EQ, [sc("1")])], b'{"id":1}\n')
    seed9("where_entry_seventeen_after_bad_field", [("id", 0x80, FORM_STR, 0, None)], many + [W(OWN_NAME, K_EQ, [sc("1")])], b'{"id":1}\n')
    seed9("where_entry_where_bad_before_dup_field", [("id", INT64, FORM_STR, 0, None), ("id", INT64, FORM_STR, 0, None)],
          [W("a", K_BAD, [bad("nan")], sub=1)], b'{"id":1}\n')
    # Generated records: nested objects along the where paths, mutations, leaves of every kind.
    gen = [W("a", K_NUMR, [num("2^53f"), num("1e19")], [">=", "<"], path=ab), W("s", K_STRR, [st("a"), st("b")], [">", "<="], path=ac),
           W("n", K_IN, [sc("5")], count=16), W("x", K_EQ, [sc("null")], path=PATH(3, 1 | 0 << 3 | 1 << 6)),
           W(OWN_NAME, K_ALL_NUM, [num("0"), num("1"), num("-1"), num("1")], [">=", "<=", ">", "<"], count=5, path=PATH(4, 3 | 4 << 3), own_leaf=True)]
    fields = [("id", INT64 + NULLABLE, FORM_TD, D["1"], PATH(2, 3 | 3 << 3)), ("b", STRING + NULLABLE, FORM_TD, D["free"], PATH(2, 0 | 3 << 3))]
    for sd in range(6):
        seed9("where_entry_generated_%d" % sd, fields, gen, b'{}\n', records=7, rec_seed=sd * 977 + 3, forced=sd >= 4)
    pair = [W("a", K_NUMR, [num("0"), num("2^64f")], [">=", "<"], path=ab), W("s", K_IN_STRR, [st("a"), st("empty")], [">="], path=ac, count=16)]
    for sd in range(4):
        seed9("where_entry_generated_pair_%d" % sd, ID, pair, b'', records=7, rec_seed=sd * 31 + 1)
    seed9("where_entry_generated_flat", [("id", INT64, FORM_STR, 0, None)], [W(n, K_SHORT, [sc(v)]) for n, v in
          [("a", "1"), ("b", "a"), ("s", "1.5"), ("n", "null"), ("x", "true")]] + [W("event", K_IN, [sc("purchase")], count=255, msrc=M_STR)],
          b'', records=7, rec_seed=4242)
    seed9("where_entry_generated_short_io_fault", fields, gen, b'{}\n', records=5, rec_seed=77, rd=1, wr=2, fault=5, fault_at=2)
    # Limit mode: 16 entries of 8-segment paths, six operators each, 256 in elements, 1024-byte operands.
    for v in range(8):
        seed9("where_entry_limit_%d" % v, [], [], b'{}\n', mode=LIMIT | v << 1, rec_seed=v * 0x11)
    seed9("where_entry_limit_short_io_faults", [], [], b'{}\n', mode=LIMIT | 6 << 1, rd=1, wr=2, fault=5, fault_at=2, rec_seed=5)

def main():
    os.makedirs(OUT, exist_ok=True)
    narrow = [("id", INT64), ("amount", FLOAT64)]
    purchase = [("event", W_STR, 2)]
    seed("narrow", narrow, purchase,
         b'{"id":1,"amount":9.5,"event":"purchase","note":"x"}\n{"id":2,"amount":3,"event":"view"}\n')
    seed("all_types", [("a", INT64), ("b", FLOAT64 + NULLABLE), ("s", STRING + NULLABLE), ("n", BOOL + NULLABLE), ("x", STRING)], [],
         b'{"a":-5,"b":null,"s":"t","n":true,"x":"u"}\n{"a":0,"b":1.25e3,"s":null,"n":null,"x":""}\n')
    seed("framing_crlf", [("a", INT64)], [], b'{"a":1}\r\n{"a":2}\r\n{"a":3}')
    seed("framing_blank_skip", [("a", INT64)], [], b'{"a":1}\n\n\r\n{"a":2}\n', skip=True)
    seed("framing_blank_error", [("a", INT64)], [], b'{"a":1}\n\n{"a":2}\n')
    seed("framing_lone_cr", [("a", INT64)], [], b'{"a":1}\r{"a":2}\n{"a":3}\r')
    seed("framing_empty", [("a", INT64)], [], b"")
    seed("cap_eight", [("a", INT64)], [], b'{"a":1}\n{"a":10}\n{"a":100}\n{"a":1000}\n', cap=1)
    seed("cap_crlf_edge", [("a", INT64)], [], b'{"a":10}\r\n{"a":10}\r\r\n', cap=1)
    seed("numbers", [("a", FLOAT64)], [],
         b'{"a":-0}\n{"a":-0.0}\n{"a":1e-400}\n{"a":5e-324}\n{"a":1.7976931348623157e308}\n'
         b'{"a":18446744073709551615}\n{"a":18446744073709551616}\n{"a":1000000000000000000000000}\n')
    seed("number_overflow", [("a", FLOAT64)], [], b'{"a":1e400}\n')
    seed("number_raw_int", [("a", INT64)], [], b'{"a":123456789012345678901234567890}\n')
    seed("int_limits", [("a", INT64)], [], b'{"a":9223372036854775807}\n{"a":-9223372036854775808}\n{"a":9223372036854775808}\n')
    seed("strings", [("s", STRING)], [],
         b'{"s":"\\u0000\\n\\t\\"\\\\\\/"}\n{"s":"\\ud83d\\ude00 caf\xc3\xa9"}\n{"s":"\xe2\x80\xa8\x7f"}\n')
    seed("bad_utf8", [("s", STRING)], [], b'{"s":"\xc0\xaf"}\n')
    seed("lone_surrogate", [("s", STRING)], [], b'{"s":"\\ud83d"}\n')
    seed("escaped_keys", [("e_acute", INT64), ("quote", INT64), ("backslash", INT64), ("tab", INT64), ("ctl", INT64), ("empty", INT64)], [],
         b'{"\\u00e9":1,"\\"q":2,"a\\\\b":3,"t\\tb":4,"\\u0001":5,"":6}\n')
    seed("duplicates", [("a", INT64)], [], b'{"a":1,"b":2,"\\u0061":3}\n')
    seed("duplicate_unreferenced", [("a", INT64)], [], b'{"a":1,"z":2,"z":3,"y":[{"q":1,"q":2}]}\n')
    seed("nesting_511", [("a", INT64)], [], b'{"a":1,"z":' + b"[" * 510 + b"]" * 510 + b"}\n")
    seed("nesting_512", [("a", INT64)], [], b'{"a":1,"z":' + b"[" * 511 + b"]" * 511 + b"}\n")
    seed("where_kinds", [("a", INT64)], [("n", W_NULL, 0), ("x", W_BOOL, 1), ("b", W_INT, 3), ("s", W_FLOAT, 1)],
         b'{"a":1,"n":null,"x":true,"b":5,"s":-0.0}\n{"a":2,"n":null,"x":true,"b":5.0,"s":0.0}\n{"a":3,"n":0,"x":true,"b":5,"s":0.0}\n')
    seed("where_non_scalar", [("a", INT64)], [("b", W_INT, 3)], b'{"a":1,"b":[1]}\n')
    seed("where_shared_key", [("a", INT64), ("event", STRING)], purchase, b'{"a":1,"event":"purchase"}\n{"a":2,"event":"other"}\n')
    seed("proto_and_int_keys", [("proto", INT64), ("zero", INT64), ("123", INT64)], [], b'{"__proto__":1,"0":2,"123":3}\n')
    seed("float_expansion", [("a", FLOAT64), ("b", FLOAT64), ("n", FLOAT64), ("x", FLOAT64), ("s", FLOAT64)], [],
         b'{"a":1e20,"b":1e20,"n":1e20,"x":1e20,"s":1e20}\n', cap=2)
    seed("where_int_high_bits", [("a", INT64)], [("b", W_INT, 2)],
         b'{"a":1,"b":18446744073709551615}\n{"a":2,"b":-1}\n')
    seed("where_int_min", [("a", INT64)], [("b", W_INT, 6)],
         b'{"a":1,"b":9223372036854775808}\n{"a":2,"b":-9223372036854775808}\n')
    seed("type_mismatch_object", [("a", INT64)], [], b'{"a":{}}\n')
    seed("bool_false_output", [("a", BOOL)], [], b'{"a":false}\n')
    seed("writer_growth", [("a", INT64), ("x", STRING)], [],
         b'{"a":1,"x":"s"}\n{"a":2,"x":"' + b"y" * 70000 + b'"}\n', cap=7)
    seed("multibyte_names", [("euro", INT64), ("emoji", INT64)], [], b'{"\xe2\x82\xac":1,"\xf0\x9f\x98\x80":2}\n')
    seed("invalid_name", [("a", INT64)], [], b'{"a":1}\n', invalid_name=0xFD)
    seed("bad_type_string", [("a", 0x80)], [], b'{"a":1}\n')
    seed("bad_json", [("a", INT64)], [], b'{"a":1,}\n')
    seed("bad_bom", [("a", INT64)], [], b'\xef\xbb\xbf{"a":1}\n')
    seed("bad_nul", [("a", INT64)], [], b'{"a":1}\x00\n')
    seed("bad_root", [("a", INT64)], [], b'[1,2]\n')
    seed("bad_truncated", [("a", INT64)], [], b'{"a":')
    seed("bad_open_flood", [("a", INT64)], [], b"[" * 300)
    seed("wide_record", [("id", INT64), ("x", STRING)], [],
         b'{' + b",".join(b'"k%d":%d' % (i, i) for i in range(60)) + b',"id":7,"x":"v"}\n')
    # The same name as an int key and as a string key ("123" and 123, "0" and 0): compile must
    # refuse it. In an earlier version of compile both fields shared one value and the writer overflowed on the long string.
    KEY_AS_STRING = 8
    seed("dup_name_fields_123", [("123", STRING), ("123", STRING | KEY_AS_STRING)], [],
         b'{"123":"' + b"y" * 70000 + b'"}\n')
    seed("dup_name_fields_0", [("zero", INT64 | KEY_AS_STRING), ("zero", INT64)], [], b'{"0":1}\n')
    seed("dup_name_where_123", [("a", INT64)], [("123", W_STR, 2), ("123", W_STR, 2 | KEY_AS_STRING)],
         b'{"a":1,"123":"purchase"}\n')
    seed("repeat_chunk_edge", [("a", INT64)], [], b'{"a":123456,"pad":"xxxxxxxxxxxxxxxxxxxxxx"}\n', repeat=12)
    seed("long_line_at_chunk", [("a", INT64)], [], b'{"a":1,"p":"' + b"y" * 65520 + b'"}\n', cap=5)
    seed("short_reads", [("id", INT64), ("amount", FLOAT64)], purchase,
         b'{"id":1,"amount":9.5,"event":"purchase"}\n' * 4, rd=1, wr=2)
    for fault, label in enumerate("none read_fail read_throw read_empty eof_throw write_fail write_zero write_throw "
                                  "write_overclaim flush_fail flush_throw flush_userland close_other timed_out input_held low_memory".split()):
        if fault == 0:
            continue
        seed("fault_" + label, narrow, purchase,
             b'{"id":1,"amount":9.5,"event":"purchase"}\n{"id":2,"amount":1,"event":"purchase"}\n',
             fault=fault, fault_at=1 if label != "low_memory" else 1)
    seeds_defaults()
    seeds_paths()
    seeds_where_entries()

main()
