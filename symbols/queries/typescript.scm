; TypeScript and TSX. A capture named after a SymbolKind value marks the declaration, @name its name. When several
; patterns capture one name, the first pattern wins.

[
  (class_declaration
    name: (type_identifier) @name)
  (abstract_class_declaration
    name: (type_identifier) @name)
] @class

(interface_declaration
  name: (type_identifier) @name) @interface

(enum_declaration
  name: (identifier) @name) @enum

(enum_body
  [
    (property_identifier) @name @enum_case
    (enum_assignment
      name: (property_identifier) @name) @enum_case
  ])

(type_alias_declaration
  name: (type_identifier) @name) @type

[
  (internal_module
    name: [(identifier) (nested_identifier)] @name)
  (module
    name: [(identifier) (nested_identifier)] @name)
  (module
    name: (string (string_fragment) @name))
] @module

(class_body
  [
    (method_definition
      name: [(property_identifier) (private_property_identifier)] @name)
    (method_signature
      name: [(property_identifier) (private_property_identifier)] @name)
    (abstract_method_signature
      name: [(property_identifier) (private_property_identifier)] @name)
  ] @method)

(class_body
  (public_field_definition
    name: [(property_identifier) (private_property_identifier)] @name) @property)

(interface_body
  (method_signature
    name: (property_identifier) @name) @method)

(interface_body
  (property_signature
    name: (property_identifier) @name) @property)

[
  (function_declaration
    name: (identifier) @name)
  (generator_function_declaration
    name: (identifier) @name)
  (function_signature
    name: (identifier) @name)
] @function

(lexical_declaration
  kind: "const"
  (variable_declarator
    name: (identifier) @name
    value: [(arrow_function) (function_expression) (generator_function)])) @function

(lexical_declaration
  kind: "const"
  (variable_declarator
    name: (identifier) @name)) @constant
