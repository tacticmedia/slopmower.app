; Rust. A capture named after a SymbolKind value marks the declaration, @name its name. In an impl block, @parent is
; the implementing type and @as the trait. When several patterns capture one name, the first pattern wins, so an
; implementing type without reference and type arguments wins over the type as written.

[
  (struct_item
    name: (type_identifier) @name)
  (union_item
    name: (type_identifier) @name)
] @struct

[
  (struct_item
    body: (field_declaration_list
      (field_declaration
        name: (field_identifier) @name) @property))
  (union_item
    body: (field_declaration_list
      (field_declaration
        name: (field_identifier) @name) @property))
]

(enum_item
  name: (type_identifier) @name) @enum

(enum_variant_list
  (enum_variant
    name: (identifier) @name) @enum_case)

(trait_item
  name: (type_identifier) @name) @trait

(trait_item
  body: (declaration_list
    [
      (function_item
        name: (identifier) @name)
      (function_signature_item
        name: (identifier) @name)
    ] @method))

(impl_item
  !trait
  type: [
    (type_identifier) @parent
    (generic_type
      type: (type_identifier) @parent)
    (reference_type
      type: [
        (type_identifier) @parent
        (generic_type
          type: (type_identifier) @parent)
      ])
  ]
  body: (declaration_list
    [
      (function_item
        name: (identifier) @name) @method
      (const_item
        name: (identifier) @name) @constant
      (type_item
        name: (type_identifier) @name) @type
    ]))

(impl_item
  trait: (_) @as
  type: [
    (type_identifier) @parent
    (generic_type
      type: (type_identifier) @parent)
    (reference_type
      type: [
        (type_identifier) @parent
        (generic_type
          type: (type_identifier) @parent)
      ])
  ]
  body: (declaration_list
    [
      (function_item
        name: (identifier) @name) @method
      (const_item
        name: (identifier) @name) @constant
      (type_item
        name: (type_identifier) @name) @type
    ]))

(impl_item
  !trait
  type: (_) @parent
  body: (declaration_list
    [
      (function_item
        name: (identifier) @name) @method
      (const_item
        name: (identifier) @name) @constant
      (type_item
        name: (type_identifier) @name) @type
    ]))

(impl_item
  trait: (_) @as
  type: (_) @parent
  body: (declaration_list
    [
      (function_item
        name: (identifier) @name) @method
      (const_item
        name: (identifier) @name) @constant
      (type_item
        name: (type_identifier) @name) @type
    ]))

(mod_item
  name: (identifier) @name) @module

(macro_definition
  name: (identifier) @name) @macro

[
  (function_item
    name: (identifier) @name)
  (function_signature_item
    name: (identifier) @name)
] @function

[
  (const_item
    name: (identifier) @name)
  (static_item
    name: (identifier) @name)
] @constant

[
  (type_item
    name: (type_identifier) @name)
  (associated_type
    name: (type_identifier) @name)
] @type
