(() => {
    const modal = document.getElementById('reviewModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalReviewText = document.getElementById('modalReviewText');
    const copyModalTextButton = document.querySelector('.js-copy-modal-text');

    const openModal = (title, text) => {
        if (!modal || !modalReviewText) return;
        if (modalTitle) {
            modalTitle.textContent = `${title}（全文）`;
        }
        modalReviewText.textContent = text || '（未設定）';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        if (!modal) return;
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
