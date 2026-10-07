; Go. A capture named after a SymbolKind value marks the declaration, @name its name, and @parent the receiver type
; without pointer and type arguments. When several patterns capture one name, the first pattern wins. A declaration
; with one spec is captured whole, so its doc comment precedes it.

(type_declaration
  .
  (type_spec
    name: (type_identifier) @name
    type: (struct_type))
  .) @struct

(type_spec
  name: (type_identifier) @name
  type: (struct_type)) @struct

(type_declaration
  .
  (type_spec
    name: (type_identifier) @name
    type: (interface_type))
  .) @interface

(type_spec
  name: (type_identifier) @name
  type: (interface_type)) @interface

(type_declaration
  .
  [
    (type_spec
      name: (type_identifier) @name)
    (type_alias
      name: (type_identifier) @name)
  ]
  .) @type

[
  (type_spec
    name: (type_identifier) @name)
  (type_alias
    name: (type_identifier) @name)
] @type

(field_declaration_list
  (field_declaration
    name: (field_identifier) @name) @property)

(interface_type
  (method_elem
    name: (field_identifier) @name) @method)

(function_declaration
  name: (identifier) @name) @function

(method_declaration
  receiver: (parameter_list
    (parameter_declaration
      type: [
        (type_identifier) @parent
        (generic_type
          type: (type_identifier) @parent)
        (pointer_type
          [
            (type_identifier) @parent
            (generic_type
              type: (type_identifier) @parent)
          ])
      ]))
  name: (field_identifier) @name) @method

(const_declaration
  .
  (const_spec
    name: (identifier) @name)
  .) @constant

(const_spec
  name: (identifier) @name) @constant
