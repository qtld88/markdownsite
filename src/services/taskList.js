/**
 * Makes "- [ ] item" checkboxes under `root` clickable. The state lives in the
 * page only: the Markdown file is not modified, and a reload resets it.
 */
export function enableTaskLists(root) {
	if (!root) { return }
	root.querySelectorAll('li > input[type=checkbox][disabled], li > p > input[type=checkbox][disabled]').forEach((box) => {
		box.removeAttribute('disabled')
	})
}
