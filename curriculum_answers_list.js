(() => {
    const modal = document.getElementById('reviewModal');
    const modalReviewText = document.getElementById('modalReviewText');

    const openModal = (text) => {
        if (!modal || !modalReviewText) return;
        modalReviewText.textContent = text || '（未設定）';
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
    };

    const closeModal = () => {
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    };

    document.querySelectorAll('.js-open-review').forEach((button) => {
        button.addEventListener('click', () => {
            openModal(button.dataset.review || '');
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

                const row = button.closest('tr');
                const reviewButton = row ? row.querySelector('.js-open-review') : null;
                if (reviewButton) {
                    reviewButton.dataset.review = data.review;
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
