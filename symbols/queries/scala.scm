; Scala. A capture named after a SymbolKind value marks the declaration, @name its name. When several patterns
; capture one name, the first pattern wins, so a `def` at the top level is a function, and the only case of a `case`
; line is captured whole.

(class_definition
  name: (identifier) @name) @class

(trait_definition
  name: (identifier) @name) @trait

(object_definition
  name: (identifier) @name) @module

(enum_definition
  name: (identifier) @name) @enum

(enum_case_definitions
  .
  [
    (simple_enum_case
      name: (identifier) @name)
    (full_enum_case
      name: (identifier) @name)
  ]
  .) @enum_case

[
  (simple_enum_case
    name: (identifier) @name)
  (full_enum_case
    name: (identifier) @name)
] @enum_case

(compilation_unit
  [
    (function_definition
      name: (_) @name)
    (function_declaration
      name: (_) @name)
  ] @function)

[
  (function_definition
    name: (_) @name)
  (function_declaration
    name: (_) @name)
] @method

[
  (val_definition
    pattern: [
      (identifier) @name
      (identifiers
        (identifier) @name)
    ])
  (var_definition
    pattern: [
      (identifier) @name
      (identifiers
        (identifier) @name)
    ])
  (val_declaration
    name: (identifier) @name)
  (var_declaration
    name: (identifier) @name)
] @property

(type_definition
  name: (type_identifier) @name) @type
