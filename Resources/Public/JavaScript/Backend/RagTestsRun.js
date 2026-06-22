/**
 * RAG-tests overview: disable the Run button + show a spinner the
 * moment the form posts. The BE round-trip takes several seconds (the
 * RAG provider call is synchronous), so without feedback the operator
 * pile up duplicate clicks and the flash queue gets confusing.
 *
 * Progressive enhancement: the form still submits the same hidden
 * uid / token; JS only adds visual feedback. If the module fails to
 * load (CSP misconfig, fetch error) the button works the same as
 * before, just without the spinner.
 *
 * Loaded via PageRenderer::loadJavaScriptModule() from
 * RagTestController::index() so the JS arrives with the proper CSP
 * nonce — no inline <script> tags.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.ws-rag-run-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('.ws-rag-run-btn');
            if (!btn || btn.disabled) {
                return;
            }
            btn.disabled = true;
            const spinner = btn.querySelector('.ws-rag-run-spinner');
            if (spinner) {
                spinner.classList.remove('d-none');
            }
        });
    });

    // Adopt-as-expected: confirm before submit since this permanently
    // replaces the regression baseline. Same progressive-enhancement
    // shape — without JS the form still posts (with no confirm).
    document.querySelectorAll('.ws-rag-adopt-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            const btn = form.querySelector('.ws-rag-adopt-btn');
            const ok = window.confirm(
                'Replace the expected answer with the last actual answer for this test? '
                + 'Make sure the actual is factually correct — this becomes the new regression baseline.',
            );
            if (!ok) {
                e.preventDefault();
                return;
            }
            if (btn) {
                btn.disabled = true;
            }
        });
    });
});
