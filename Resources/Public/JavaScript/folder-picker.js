/**
 * Minimal folder picker for the ws_meilisearch BE module. Wires up any
 * `<button data-wsm-folder-picker="<inputId>" data-wsm-folder-picker-url="<browserUrl>">`
 * to TYPO3's standard folder element-browser and writes the chosen
 * combined identifier (e.g. "1:/handbooks/") back into the bound <input>.
 *
 * Mirrors the FormEngine pattern (see TYPO3 core's form-engine.js
 * `.delegateTo(document, ".t3js-element-browser")` handler):
 *   1. Open the picker URL with useEvents=1 → the picker dispatches a
 *      `typo3:element-browser:message` CustomEvent on its iframe element
 *      instead of doing a postMessage with origin checks.
 *   2. Listen for that CustomEvent on the modal iframe, read the
 *      `elementAdded` action, write the value to our input, close the
 *      modal. No window.postMessage, no opener-window tree-walking.
 */
import Modal from "@typo3/backend/modal.js";

class FolderPicker {
  constructor() {
    document.addEventListener("click", (evt) => {
      const trigger = evt.target.closest("[data-wsm-folder-picker]");
      if (!trigger) {
        return;
      }
      evt.preventDefault();
      this.openPicker(trigger);
    });
  }

  openPicker(trigger) {
    const targetId = trigger.dataset.wsmFolderPicker;
    const baseUrl = trigger.dataset.wsmFolderPickerUrl;
    if (!targetId || !baseUrl) {
      return;
    }
    // Modern separate-parameter API (v14+). The legacy `bparams`
    // pipe-delimited string is parsed by `fromBparams()` which
    // doesn't read `useEvents` — and useEvents=1 is what makes the
    // picker fire a bubbling CustomEvent on its iframe (instead of
    // a window.postMessage that loses origin in nested modals).
    const url = new URL(baseUrl, window.location.origin);
    url.searchParams.set("mode", "folder");
    url.searchParams.set("fieldReference", targetId);
    url.searchParams.set("useEvents", "1");

    const modal = Modal.advanced({
      type: Modal.types.iframe,
      content: url.toString(),
      title: trigger.title || "Select folder",
      size: Modal.sizes.large,
    });

    // The modal iframe dispatches a bubbling CustomEvent when an
    // element is picked; useEvents=1 makes the picker take that path.
    modal.addEventListener("typo3:element-browser:message", (evt) => {
      const detail = evt.detail || {};
      if (detail.actionName !== "typo3:elementBrowser:elementAdded") {
        return;
      }
      const input = document.getElementById(detail.fieldName);
      if (input) {
        input.value = detail.value;
        input.dispatchEvent(new Event("change", { bubbles: true }));
      }
      modal.hideModal();
    });
  }
}

export default new FolderPicker();
