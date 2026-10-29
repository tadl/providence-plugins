/** Use the native navigation container after it has been rendered. */
// SPDX-License-Identifier: GPL-3.0-or-later
(function () {
    'use strict';
    function positionPreservation() {
        const sidebar = document.getElementById('leftNavSidebar');
        const item = document.getElementById('mdpnPreservationMenuItem');
        if (!sidebar || !item) return;
        const end = sidebar.querySelector('.editorBottomPadding');
        const collectionMenu = document.getElementById('mdpnCollectionMenu');
        if (collectionMenu) sidebar.insertBefore(collectionMenu, end);
        sidebar.insertBefore(item, end);
        if (typeof window.caResizeSideNav === 'function') window.caResizeSideNav();
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', positionPreservation, {once: true});
    } else {
        positionPreservation();
    }
}());
