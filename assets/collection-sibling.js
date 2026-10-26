/* Embedded by collectionSiblingPlugin; no global functions or core replacements. */
function initializeCollectionSibling(options) {
    'use strict';
    jQuery(function () {
        const row = document.getElementById(options.prefix + 'HierarchyBrowseAdd');
        const mode = document.getElementById(options.prefix + 'addType');
        const type = document.getElementById(options.prefix + 'typeList');
        if (!row || !mode || !type || !row.contains(mode) || !row.contains(type)
            || row.dataset.collectionSibling || mode.disabled || type.disabled) {
            return;
        }
        const link = row.querySelector('a[onclick*="_navigateToNewForm("]');
        if (!link || mode.querySelector('option[value="next_to"]')
            || mode.querySelector('option[value="collection_sibling"]')) {
            return;
        }

        mode.add(new Option(options.label, 'collection_sibling'));
        row.dataset.collectionSibling = '1';
        if (!link.hasAttribute('aria-label')) {
            link.setAttribute('aria-label', options.createLabel);
        }
        // Capture before the native inline handler, and only for our new action.
        link.addEventListener('click', function (event) {
            if (mode.value !== 'collection_sibling') { return; }
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!/^[1-9][0-9]*$/.test(type.value)) { return; }

            // Opening the standard form preserves its permissions, CSRF handling,
            // unsaved-change warning and collection access plugin checks.
            window.location.assign(options.editorUrl
                + '/type_id/' + encodeURIComponent(type.value)
                + '/collection_id/' + options.collectionId);
        }, true);
    });
}
