(() => {
    const modal = document.getElementById('reviewModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalReviewText = document.getElementById('modalReviewText');
    const modalMedia = document.getElementById('modalMedia');
    const copyModalTextButton = document.querySelector('.js-copy-modal-text');

    const openPromptTemplateModalButton = document.getElementById('openPromptTemplateModal');
    const promptTemplateModal = document.getElementById('promptTemplateModal');
    const promptTemplateSelect = document.getElementById('promptTemplateSelect');
    const promptTemplateBody = document.getElementById('promptTemplateBody');
    const promptTemplateMeta = document.getElementById('promptTemplateMeta');
    const promptTemplateMessage = document.getElementById('promptTemplateMessage');
    const savePromptTemplateButton = document.getElementById('savePromptTemplateButton');
    let promptTemplates = [];

    const imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'avif'];
    const videoExtensions = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];

    const asHttpUrl = (value) => {
        const trimmed = (value || '').trim();
        if (!trimmed) return null;

        try {
            const parsed = new URL(trimmed);
            if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
                return parsed;
            }
            return null;
        } catch (error) {
            return null;
        }
    };

    const detectMediaType = (value) => {
        const parsedUrl = asHttpUrl(value);
        if (!parsedUrl) return null;

        const path = parsedUrl.pathname || '';
        const extension = path.includes('.') ? path.split('.').pop().toLowerCase() : '';

        if (imageExtensions.includes(extension)) return 'image';
        if (videoExtensions.includes(extension)) return 'video';
        return 'image';
    };

    const resetModalContent = () => {
        if (modalReviewText) {
            modalReviewText.hidden = false;
            modalReviewText.textContent = '';
        }

        if (modalMedia) {
            modalMedia.hidden = true;
            modalMedia.replaceChildren();
        }

        if (copyModalTextButton) {
            copyModalTextButton.disabled = false;
        }
    };

    const showTextInModal = (text) => {
        if (!modalReviewText) return;
        modalReviewText.hidden = false;
        modalReviewText.textContent = text || '（未設定）';
        if (copyModalTextButton) {
            copyModalTextButton.disabled = false;
        }
    };

    const showMediaInModal = (value) => {
        if (!modalMedia || !modalReviewText) {
            showTextInModal(value);
            return;
        }

        const mediaType = detectMediaType(value);
        if (!mediaType) {
            showTextInModal(value);
            return;
        }

        const parsedUrl = asHttpUrl(value);
        if (!parsedUrl) {
            showTextInModal(value);
            return;
        }

        modalReviewText.hidden = false;
        modalReviewText.textContent = parsedUrl.href;
        modalMedia.hidden = false;

        if (mediaType === 'video') {
            const video = document.createElement('video');
            video.controls = true;
            video.preload = 'metadata';
            video.src = parsedUrl.href;
            modalMedia.appendChild(video);
        } else {
            const image = document.createElement('img');
            image.src = parsedUrl.href;
            image.alt = '提出物プレビュー';
            image.addEventListener('error', () => {
                resetModalContent();
                showTextInModal(value);
            }, { once: true });
            modalMedia.appendChild(image);
        }

        if (copyModalTextButton) {
            copyModalTextButton.disabled = false;
        }
    };

    const openModal = (title, text) => {
        if (!modal || !modalReviewText) return;
        resetModalContent();
        if (modalTitle) {
            modalTitle.textContent = `${title}（全文）`;
        }
        showMediaInModal(text || '');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        if (!modal) return;
        resetModalContent();
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    };

    document.querySelectorAll('.js-open-value').forEach((button) => {
        button.addEventListener('click', () => {
            openModal(button.dataset.title || '項目', button.dataset.value || '');
        });
    });

    document.querySelectorAll('.js-close-modal').forEach((elem) => {
        elem.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeModal();
        }
    });

    if (copyModalTextButton) {
        copyModalTextButton.addEventListener('click', async () => {
            if (!modalReviewText) return;

            try {
                await navigator.clipboard.writeText(modalReviewText.textContent || '');
                const originalText = copyModalTextButton.textContent;
                copyModalTextButton.textContent = 'コピーしました';
                setTimeout(() => {
                    copyModalTextButton.textContent = originalText || 'コピー';
                }, 1200);
            } catch (error) {
                alert('コピーに失敗しました。');
            }
        });
    }

    const setPromptTemplateMessage = (message, isError = false) => {
        if (!promptTemplateMessage) return;
        promptTemplateMessage.textContent = message;
        promptTemplateMessage.classList.toggle('is-error', isError);
    };

    const closePromptTemplateModal = () => {
        if (!promptTemplateModal) return;
        promptTemplateModal.classList.remove('is-open');
        promptTemplateModal.setAttribute('aria-hidden', 'true');
    };

    const renderPromptTemplate = () => {
        if (!promptTemplateSelect || !promptTemplateBody || !promptTemplateMeta) return;
        const targetId = Number(promptTemplateSelect.value || 0);
        const selected = promptTemplates.find((item) => Number(item.id) === targetId);

        if (!selected) {
            promptTemplateBody.value = '';
            promptTemplateMeta.textContent = '';
            return;
        }

        promptTemplateBody.value = selected.template_body || '';
        promptTemplateMeta.textContent = `curriculum_id: ${selected.curriculum_id} / curriculum_name: ${selected.curriculum_name || '（未設定）'} / version: ${selected.version} / status: ${selected.status}`;
    };

    const openPromptTemplateModal = async () => {
        if (!promptTemplateModal || !promptTemplateSelect) return;

        setPromptTemplateMessage('読み込み中...');
        promptTemplateModal.classList.add('is-open');
        promptTemplateModal.setAttribute('aria-hidden', 'false');

        try {
            const url = `${window.location.pathname}?action=list_prompt_templates`;
            const response = await fetch(url, { method: 'GET' });
            const data = await response.json();

            if (!response.ok || !data.ok) {
                throw new Error(data.message || 'テンプレート一覧の取得に失敗しました');
            }

            promptTemplates = Array.isArray(data.templates) ? data.templates : [];
            promptTemplateSelect.innerHTML = '';

            promptTemplates.forEach((template) => {
                const option = document.createElement('option');
                option.value = String(template.id || '');
                option.textContent = `#${template.id} ${template.curriculum_name || '（名称なし）'} v${template.version} [${template.status}]`;
                promptTemplateSelect.appendChild(option);
            });

            if (promptTemplates.length === 0) {
                setPromptTemplateMessage('テンプレートが見つかりません。', true);
            } else {
                setPromptTemplateMessage('');
                renderPromptTemplate();
            }
        } catch (error) {
            const message = error instanceof Error ? error.message : 'テンプレート一覧の取得に失敗しました';
            setPromptTemplateMessage(message, true);
        }
    };

    if (openPromptTemplateModalButton) {
        openPromptTemplateModalButton.addEventListener('click', openPromptTemplateModal);
    }

    if (promptTemplateSelect) {
        promptTemplateSelect.addEventListener('change', () => {
            setPromptTemplateMessage('');
            renderPromptTemplate();
        });
    }

    document.querySelectorAll('.js-close-prompt-template-modal').forEach((elem) => {
        elem.addEventListener('click', closePromptTemplateModal);
    });

    if (savePromptTemplateButton) {
        savePromptTemplateButton.addEventListener('click', async () => {
            if (!promptTemplateSelect || !promptTemplateBody) return;

            const templateId = Number(promptTemplateSelect.value || 0);
            const templateBody = promptTemplateBody.value || '';

            if (templateId <= 0) {
                setPromptTemplateMessage('テンプレートを選択してください。', true);
                return;
            }

            if (templateBody.trim() === '') {
                setPromptTemplateMessage('template_body を入力してください。', true);
                return;
            }

            savePromptTemplateButton.disabled = true;
            const originalText = savePromptTemplateButton.textContent;
            savePromptTemplateButton.textContent = '保存中...';

            try {
                const params = new URLSearchParams();
                params.set('action', 'update_prompt_template');
                params.set('template_id', String(templateId));
                params.set('template_body', templateBody);

                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: params.toString()
                });

                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || 'テンプレートの更新に失敗しました');
                }

                const selected = promptTemplates.find((item) => Number(item.id) === templateId);
                if (selected) {
                    selected.template_body = templateBody;
                }

                setPromptTemplateMessage('保存しました。');
            } catch (error) {
                const message = error instanceof Error ? error.message : 'テンプレートの更新に失敗しました';
                setPromptTemplateMessage(message, true);
            } finally {
                savePromptTemplateButton.disabled = false;
                savePromptTemplateButton.textContent = originalText || '保存';
            }
        });
    }


    document.querySelectorAll('.js-done-checkbox').forEach((checkbox) => {
        checkbox.addEventListener('change', async () => {
            const caId = checkbox.dataset.caId;
            if (!caId) return;

            const nextDone = checkbox.checked;
            const card = checkbox.closest('.answer-card');
            checkbox.disabled = true;

            try {
                const params = new URLSearchParams();
                params.set('action', 'update_done');
                params.set('ca_id', caId);
                params.set('done', nextDone ? '1' : '0');

                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: params.toString()
                });

                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || '完了状態の更新に失敗しました');
                }

                checkbox.checked = Boolean(data.done);
                if (card) {
                    card.classList.toggle('is-done', checkbox.checked);
                }
            } catch (error) {
                checkbox.checked = !nextDone;
                if (card) {
                    card.classList.toggle('is-done', checkbox.checked);
                }
                const message = error instanceof Error ? error.message : '完了状態の更新に失敗しました';
                alert(message);
            } finally {
                checkbox.disabled = false;
            }
        });
    });
    document.querySelectorAll('.js-api-btn').forEach((button) => {
        button.addEventListener('click', async () => {
            const caId = button.dataset.caId;
            if (!caId) return;

            button.disabled = true;
            const originalText = button.textContent;
            button.textContent = '更新中...';

            try {
                const params = new URLSearchParams();
                params.set('action', 'update_review');
                params.set('ca_id', caId);

                const response = await fetch(window.location.pathname + window.location.search, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: params.toString()
                });

                const data = await response.json();
                if (!response.ok || !data.ok) {
                    throw new Error(data.message || '更新に失敗しました');
                }

                const card = button.closest('.answer-card');
                const reviewButton = card ? Array.from(card.querySelectorAll('.js-open-value')).find((elem) => elem.dataset.title === '総評') : null;
                if (reviewButton) {
                    reviewButton.dataset.value = data.review;
                    reviewButton.textContent = data.review;
                }
            } catch (error) {
                const message = error instanceof Error ? error.message : '更新に失敗しました';
                alert(message);
            } finally {
                button.disabled = false;
                button.textContent = originalText || 'API';
            }
        });
    });
})();
