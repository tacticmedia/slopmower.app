; C#. A capture named after a SymbolKind value marks the declaration, @name its name. When several patterns capture
; one name, the first pattern wins, so a record struct is a struct. A namespace is no symbol.

(class_declaration
  name: (identifier) @name) @class

(record_declaration
  "struct"
  name: (identifier) @name) @struct

(record_declaration
  name: (identifier) @name) @class

(struct_declaration
  name: (identifier) @name) @struct

(interface_declaration
  name: (identifier) @name) @interface

(enum_declaration
  name: (identifier) @name) @enum

(enum_member_declaration
  name: (identifier) @name) @enum_case

(delegate_declaration
  name: (identifier) @name) @type

[
  (method_declaration
    name: (identifier) @name)
  (constructor_declaration
    name: (identifier) @name)
] @method

[
  (property_declaration
    name: (identifier) @name)
  (event_declaration
    name: (identifier) @name)
  (field_declaration
    (variable_declaration
      (variable_declarator
        name: (identifier) @name)))
  (event_field_declaration
    (variable_declaration
      (variable_declarator
        name: (identifier) @name)))
] @property
