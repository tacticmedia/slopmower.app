# Browse the code

The **Code** tab works like the file browser of GitHub. It shows the files of the review commit, and they never change. To comment on the code, see [Comment on code](comment.md).

<!-- On this page -->

## The file tree

The page scrolls: the review header moves out of the window, and the file tree and the file fill the window. The tree stays at the left with the height of the window and scrolls on its own.

- Drag the edge of the tree, or focus it and press the arrow keys, to change its width.
- **Collapse file tree** hides the tree. **Expand file tree**, before the path of the file, shows it again.
- Your browser remembers the width and whether the tree is hidden.

On a narrow screen, the tree is above the file and scrolls away with the page.

## Folders and files

Without an open file, the tab shows the root directory as a table: the name, the size of each file, and a mark on viewed files. Click a directory in the table or in the tree to see its table. The chevron in the tree only opens and closes a directory. A `README.md` of the directory shows below the table.

## The file view

Above a file, each part of the path links to its directory, and **Copy path** copies the path. The toolbar shows the number of lines and the size, the file commands, **Raw**, which opens the file as plain text, and buttons that copy and download the whole file.

When you scroll into the code, the toolbar stays at the top of the window, with the path and **Top** above it. **Top** goes back to the top of the page. In a file with symbols, the declaration lines of the class, the module, the function, and the Twig block around the top line stay below the toolbar. Click one to go to it.

## Wrap long lines

**Wrap lines** in the file header wraps long lines in the code and in the changes of linked commits, and your browser remembers the choice.

## Viewed files

Select **Viewed** in the file header when you are finished with a file. The file tree and the directory tables mark viewed files. The marks stay for the whole review, because the code of the review never changes.

## Blame

Click **Blame** in the toolbar of a file to see who last changed each line, up to the commit of the review. **Code** goes back. The line in the address stays selected.

A block at the left of the code covers the lines of one commit: the age of the commit, its author, and the first line of its message. Point at a block for the full date and the commit SHA. The colour bar shows the age, from light for old commits to dark for the last days, as the legend **Older** to **Newer** in the toolbar shows.

When the repository has a `.git-blame-ignore-revs` file, the commits that it names are skipped, as on GitHub, and a note says so. The blame view has no comments: go back to **Code** to comment.

## Quick open

Click **Quick open** in the review header, or press Cmd+K (Ctrl+K outside macOS), and type a part of a file path or of a symbol name. You can also type the starts of the words of a name: `CE` or `ComEx` finds `CommandExecutor`, and `ReqDec` finds `RequestDecider`. A word starts after a character such as `_` or `.`, at a capital letter after a lower-case letter or a digit, and at the last capital letter before a lower-case letter, so `HTTPClient` has the words `HTTP` and `Client`. Case does not matter.

The list shows the files and the symbols of the review commit that match your text. A name equal to your text comes first, then names that start with it, then names whose word starts match it, then names that contain it, then paths that contain it. Within each group, shorter names come first, then shorter paths.

- Move through the list with the arrow keys and press Enter, or click a hit.
- A file opens at its start. A symbol opens its file at the first line of its declaration, below any doc comment, and that line is selected.
- Escape closes the dialog.

The shortcut does nothing while you type in a comment box or another text field. **Index in progress** means that the symbol index has not finished, so some files and symbols are not in the list yet. **Index failed: hits may be missing** means that the symbol index of the review failed, so the list can stay incomplete.

## Symbols

Slopmower indexes the symbols of the review commit: classes, methods, functions, and other declarations. **Symbols** in the file header lists the symbols of the file. Choose one to comment on it, as [Comment on code](comment.md#with-the-mouse) shows. **Quick open** and **Go to declaration** use the same index.

Symbols are available for PHP, Twig, JavaScript, JSX, TypeScript, TSX, Python, Go, Rust, Java, C#, Ruby, Bash, Scala, Zig, and Lua files. In Twig, a block and a macro are symbols, for example `content` and `row()`, and a `{## #}` documentation comment above the tag is part of the range. A block inside `{% embed %}` is not a symbol. A Zig file with an empty `struct {}`, `enum {}`, `union {}`, or `opaque {}` has no symbols, because the Zig grammar refuses an empty container.

A symbol range includes the doc comment above the symbol, and the decorators, annotations, or attributes of its declaration. A Python docstring is inside the range.

In languages other than PHP, Rust, and Ruby, a symbol name joins the names of its class, interface, struct, enum, object, or namespace with `.`, for example `InvoiceTotals.total()`. A Java or Scala package and a C# namespace are not part of the name. A Go method takes the name of its receiver type, for example `Totals.Sum()`, and a Lua function `M.total` or method `M:count` takes the name of its table, `M.total()` and `M.count()`. Rust and Ruby join names with `::`, for example `Total::new()` and `Billing::InvoiceTotals::total()`, and a Rust method of a trait implementation is `<Total as From<i64>>::from()`.

When a file has no symbols, or they could not be loaded, **Symbols** is unavailable, and its tooltip says why.

## Go to a declaration

In a PHP file, hold Cmd (Ctrl outside macOS) and click a name to open its declaration at the review commit. The name is underlined while you hold the key. A plain click still selects text. This works for class names in any position, `Type::method()`, `Type::CONSTANT`, `Type::$property`, enum cases, `self`, `static`, and `parent`, function calls, and constants.

With the keyboard, move to **Go to declaration** in the file header with Tab, type a name, and press Enter. The name resolves like PHP code in the open file, with its namespace and its `use` imports. Start a fully qualified name with `\`, for example `\App\Billing\Invoice`, and a name in the namespace of the file with `namespace\`. A member takes the form `Invoice::total` or `Invoice::$lines`.

- One declaration opens at its first line, below any doc comment, and that line is selected. The file opens when the declaration is in another file.
- Several declarations open a menu. Choose one.
- **No declaration in this commit** means that the review commit does not declare the name, for example a vendor class that is not committed, or that the symbol index has not reached the file yet.

The browser's Back button returns to the line that you clicked. A method call on a variable, such as `$invoice->total()`, has no target, because it needs the type of the variable.
