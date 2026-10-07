; Python. A capture named after a SymbolKind value marks the declaration, @name its name. When several patterns
; capture one name, the first pattern wins.

(class_definition
  name: (identifier) @name) @class

(class_definition
  body: (block
    [
      (function_definition
        name: (identifier) @name) @method
      (decorated_definition
        definition: (function_definition
          name: (identifier) @name) @method)
    ]))

(function_definition
  name: (identifier) @name) @function
