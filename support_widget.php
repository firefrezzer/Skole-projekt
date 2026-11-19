<div class="fixed bottom-5 right-5 z-[100]">
    <section id="support-chat-panel" class="mb-4 hidden h-[min(680px,calc(100dvh-110px))] w-[min(420px,calc(100vw-2.5rem))] overflow-hidden rounded-3xl border border-slate-700 bg-slate-950 shadow-2xl shadow-black/50" aria-label="IT-support">
        <div class="flex items-center justify-between border-b border-white/10 bg-slate-900 px-4 py-3 text-white">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-600"><i class="fa-solid fa-headset" aria-hidden="true"></i></span>
                <div>
                    <p class="font-bold">IT-support</p>
                    <p class="text-xs text-slate-400">Skriv til skolens support</p>
                </div>
            </div>
            <button type="button" id="support-chat-close" class="flex h-10 w-10 items-center justify-center rounded-xl text-slate-300 transition hover:bg-white/10 hover:text-white" aria-label="Luk supportchat">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
        <iframe id="support-chat-frame" title="Skriv til IT-support" class="h-[calc(100%-65px)] w-full border-0 bg-slate-950" data-src="support.php?embed=1"></iframe>
    </section>
    <button type="button" id="support-chat-open" class="ml-auto flex items-center gap-3 rounded-full bg-blue-600 px-5 py-4 font-bold text-white shadow-xl shadow-blue-950/40 transition hover:-translate-y-0.5 hover:bg-blue-500 focus:outline-none focus:ring-4 focus:ring-blue-300/40" aria-controls="support-chat-panel" aria-expanded="false">
        <i class="fa-solid fa-headset text-lg" aria-hidden="true"></i>
        <span>Support</span>
    </button>
</div>
<script>
    (() => {
        const panel = document.getElementById('support-chat-panel');
        const openButton = document.getElementById('support-chat-open');
        const closeButton = document.getElementById('support-chat-close');
        const frame = document.getElementById('support-chat-frame');

        if (!panel || !openButton || !closeButton || !frame) {
            return;
        }

        const setOpen = (isOpen) => {
            panel.classList.toggle('hidden', !isOpen);
            openButton.setAttribute('aria-expanded', String(isOpen));
            if (isOpen && !frame.src) {
                frame.src = frame.dataset.src;
            }
            if (isOpen) {
                closeButton.focus();
            } else {
                openButton.focus();
            }
        };

        openButton.addEventListener('click', () => setOpen(panel.classList.contains('hidden')));
        closeButton.addEventListener('click', () => setOpen(false));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !panel.classList.contains('hidden')) {
                setOpen(false);
            }
        });
    })();
</script>
