; Java. A capture named after a SymbolKind value marks the declaration, @name its name. When several patterns
; capture one name, the first pattern wins.

[
  (class_declaration
    name: (identifier) @name)
  (record_declaration
    name: (identifier) @name)
] @class

[
  (interface_declaration
    name: (identifier) @name)
  (annotation_type_declaration
    name: (identifier) @name)
] @interface

(enum_declaration
  name: (identifier) @name) @enum

(enum_constant
  name: (identifier) @name) @enum_case

[
  (method_declaration
    name: (identifier) @name)
  (constructor_declaration
    name: (identifier) @name)
  (compact_constructor_declaration
    name: (identifier) @name)
  (annotation_type_element_declaration
    name: (identifier) @name)
] @method

[
  (field_declaration
    declarator: (variable_declarator
      name: (identifier) @name))
  (constant_declaration
    declarator: (variable_declarator
      name: (identifier) @name))
] @property
