(() => {
    const actions = document.getElementById('directorist-ai-listing-actions');
    const addListing = document.querySelector('.wrap > .page-title-action');

    if (actions && addListing) {
        addListing.after(actions.content);
    }

    const actionSelector = '.directorist-ai-admin-action';
    const tooltipSelector = '.directorist-ai-admin-action__tooltip';
    const dismissedClass = 'directorist-ai-admin-action--tooltip-dismissed';
    const activeTooltipSelector = `${actionSelector}:is(:hover, :focus-visible, [data-directorist-ai-contact-open]):not(.${dismissedClass}) > ${tooltipSelector}`;
    let tooltipSequence = 0;

    function cloneAction(templateId) {
        const template = document.getElementById(templateId);
        const action = template?.content.firstElementChild.cloneNode(true);

        if (action) {
            const tooltip = action.querySelector(tooltipSelector);
            tooltip.id += '-instance-' + ++tooltipSequence;
            action.setAttribute('aria-describedby', tooltip.id);
        }

        return action;
    }

    function mountDirectoryAction() {
        const toolbar = document.querySelector('.directorist-directory-type-top-right');
        if (toolbar && !toolbar.querySelector(actionSelector)) {
            const button = cloneAction('directorist-ai-directory-builder-action');
            if (button) {
                toolbar.prepend(button);
            }
        }
    }

    function mountFieldAction(field, anchor, fixedTemplate, prepend = false) {
        if (!field || !anchor || field.dataset.directoristAiLockedField) {
            return;
        }

        const editor = field.id && window.tinymce?.get(field.id);
        const value = editor && !editor.isHidden() ? editor.getContent({ format: 'text' }) : field.value;
        const template = fixedTemplate || (value?.trim() ? 'directorist-ai-field-edit' : 'directorist-ai-field-generate');
        const button = cloneAction(template);

        if (button) {
            field.dataset.directoristAiLockedField = 'true';
            if (prepend) {
                anchor.prepend(button);
            } else {
                anchor.append(button);
            }
        }
    }

    function mountEditorActions() {
        const launcher = cloneAction('directorist-ai-listing-editor-action');
        const form = document.querySelector('#post, #directorist-add-listing-form');

        if (!launcher || !form) {
            return;
        }

        const isAdmin = form.id === 'post';
        if (isAdmin && addListing) {
            addListing.after(launcher);
        } else {
            form.prepend(launcher);
        }

        function mountFields() {
            const fields = form.querySelectorAll(
                '#title, #content, #excerpt, .directorist-form-group textarea, .directorist-form-group input:is([type="text"], [type="email"], [type="url"], [type="tel"], [type="number"])'
            );

            for (const field of fields) {
                if (field.disabled || field.readOnly || field.dataset.directoristAiLockedField) {
                    continue;
                }

                const editor = field.id && document.getElementById('wp-' + field.id + '-wrap');
                const mediaToolbar = editor && editor.querySelector('.wp-media-buttons');
                if (mediaToolbar) {
                    mountFieldAction(field, mediaToolbar);
                    continue;
                }

                const container = editor || field.closest('label') || field;
                if (!container.getClientRects().length) {
                    continue;
                }

                const label = field.labels?.[0];
                if (!label?.textContent.trim() && field.id !== 'title') {
                    continue;
                }

                const header = document.createElement('div');
                header.className = 'directorist-ai-admin-action-field-header';
                mountFieldAction(field, header);
                if (
                    label && label !== container &&
                    label.parentElement === container.parentElement &&
                    !label.classList.contains('screen-reader-text')
                ) {
                    label.before(header);
                    header.prepend(label);
                } else {
                    container.before(header);
                }
            }

            if (!isAdmin) {
                return;
            }

            const labels = document.getElementById('directorist-ai-field-generate').dataset;
            for (const media of form.querySelectorAll('.listing-prv-img-container, .listing-img-container')) {
                if (!media.dataset.directoristAiLockedField) {
                    const header = document.createElement('div');
                    header.className = 'directorist-ai-admin-action-field-header';
                    header.textContent = media.classList.contains('listing-prv-img-container') ? labels.previewLabel : labels.galleryLabel;
                    mountFieldAction(media, header, 'directorist-ai-field-generate');
                    media.before(header);
                }
            }

            for (const image of form.querySelectorAll('.listing-img-container .single_attachment')) {
                mountFieldAction(image, image, 'directorist-ai-media-replace');
            }

            for (const terms of form.querySelectorAll('#at_biz_dir-categorydiv, #tagsdiv-at_biz_dir-tags')) {
                mountFieldAction(terms, terms.querySelector('.inside'), 'directorist-ai-choose-terms', true);
            }
        }

        mountFields();
        let fieldsPending = false;
        const queueFields = () => {
            if (!fieldsPending) {
                fieldsPending = true;
                window.requestAnimationFrame(() => {
                    fieldsPending = false;
                    mountFields();
                });
            }
        };
        new MutationObserver(queueFields).observe(form, { childList: true, subtree: true });
        form.addEventListener('change', queueFields);
    }

    function positionTooltip(tooltip) {
        const edgeGap = 8;
        const offsetProperty = '--directorist-ai-tooltip-offset';
        tooltip.style.removeProperty(offsetProperty);

        const { left, right } = tooltip.getBoundingClientRect();
        const minOffset = edgeGap - left;
        const maxOffset = document.documentElement.clientWidth - edgeGap - right;
        const offset = Math.min(Math.max(0, minOffset), maxOffset);

        if (offset) {
            const isRtl = getComputedStyle(tooltip).direction === 'rtl';
            tooltip.style.setProperty(offsetProperty, `${isRtl ? -offset : offset}px`);
        }
    }

    function initTooltips() {
        const revealTooltip = (action) => {
            action.classList.remove(dismissedClass);
            positionTooltip(action.querySelector(tooltipSelector));
        };

        document.addEventListener('pointerover', (event) => {
            const action = event.target.closest(actionSelector);
            if (action && !action.contains(event.relatedTarget)) {
                revealTooltip(action);
            }
        });
        document.addEventListener('focusin', (event) => {
            const action = event.target.closest(actionSelector);
            if (action) {
                revealTooltip(action);
            }
        });
        document.addEventListener('focusout', (event) => {
            event.target.closest(actionSelector)?.removeAttribute('data-directorist-ai-contact-open');
        });
        document.addEventListener('click', (event) => {
            if (event.target.closest(tooltipSelector)) {
                event.preventDefault();
                return;
            }
            const action = event.target.closest('[data-directorist-ai-contact-admin]');
            if (action) {
                action.setAttribute('data-directorist-ai-contact-open', '');
                revealTooltip(action);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            for (const tooltip of document.querySelectorAll(activeTooltipSelector)) {
                tooltip.parentElement.classList.add(dismissedClass);
            }
        });

        let resizePending = false;
        window.addEventListener('resize', () => {
            if (resizePending) {
                return;
            }

            resizePending = true;
            window.requestAnimationFrame(() => {
                resizePending = false;
                document.querySelectorAll(activeTooltipSelector).forEach(positionTooltip);
            });
        });
    }

    function initNativeToolbarTooltip() {
        const selector = '[data-directorist-ai-native-lock]';
        const tooltip = document.createElement('span');
        tooltip.className = 'directorist-ai-admin-action__tooltip directorist-ai-admin-action__tooltip--portal';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.hidden = true;
        document.body.append(tooltip);

        let action;

        function position() {
            if (!action) {
                return;
            }

            const button = action.getBoundingClientRect();
            const { width, height } = tooltip.getBoundingClientRect();
            const maxLeft = document.documentElement.clientWidth - width - 8;
            tooltip.style.left = Math.max(8, Math.min(button.left, maxLeft)) + 'px';
            tooltip.style.top = (button.bottom + height + 13 <= window.innerHeight
                ? button.bottom + 5
                : Math.max(8, button.top - height - 5)) + 'px';
        }

        function reveal(button) {
            action = button;
            const menu = button.closest('[role="menu"]');
            const container = menu?.closest('[role="presentation"]') || document.body;
            if (tooltip.parentElement !== container) {
                container.append(tooltip);
            }
            tooltip.removeAttribute('aria-hidden');
            tooltip.id = button.getAttribute('aria-describedby');
            tooltip.textContent = button.dataset.directoristAiNativeLock;
            tooltip.hidden = false;
            position();
        }

        function dismiss() {
            action = null;
            tooltip.hidden = true;
        }

        document.addEventListener('pointerover', (event) => {
            const button = event.target.closest?.(selector);
            if (button && !button.contains(event.relatedTarget)) {
                reveal(button);
            }
        });
        document.addEventListener('pointerout', (event) => {
            if (action && !action.matches(':focus-visible') &&
                (action.contains(event.target) || tooltip.contains(event.target)) &&
                !action.contains(event.relatedTarget) && !tooltip.contains(event.relatedTarget)) {
                dismiss();
            }
        });
        document.addEventListener('focusin', (event) => {
            const button = event.target.closest?.(selector);
            if (button?.matches(':focus-visible')) {
                reveal(button);
            }
        });
        document.addEventListener('focusout', (event) => {
            if (action && action.contains(event.target) && !action.matches(':hover')) {
                dismiss();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (action && event.key === 'Escape') {
                dismiss();
            }
        }, true);
        window.addEventListener('resize', position);
        window.addEventListener('scroll', position, { passive: true, capture: true });
    }

    if (document.getElementById('directorist-ai-directory-builder-action')) {
        document.addEventListener('directorist:builder:mounted', mountDirectoryAction);
        mountDirectoryAction();
    }
    mountEditorActions();
    initTooltips();
    if (document.querySelector('template[data-directorist-ai-native-toolbar]')) {
        initNativeToolbarTooltip();
    }

    // The account menu and refresh form initialize on the Themes & Extensions page.
    if (new URLSearchParams(window.location.search).get('directorist_ai_manage_license') === '1') {
        window.addEventListener('load', () => {
            const account = document.querySelector('.directorist-te-avatar');
            const refresh = document.querySelector('#directorist-te-account-dropdown .purchase-refresh-btn');

            if (!account || !refresh) {
                return;
            }

            if (account.getAttribute('aria-expanded') !== 'true') {
                account.click();
            }

            refresh.click();
        }, { once: true });
    }
})();
