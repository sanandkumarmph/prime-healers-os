@once
<script src="{{ asset('js/phos-sales-sharing.js').'?v='.hash_file('sha256', public_path('js/phos-sales-sharing.js')) }}" defer></script>
<script>
document.getElementById('copy-quote-message')?.addEventListener('click', async () => {
    const input = document.getElementById('quote-message'), status = document.getElementById('message-copy-status');
    try { await navigator.clipboard.writeText(input.value); status.textContent = 'Message copied. Review the recipient and send from your app.'; }
    catch { input.focus(); input.select(); status.textContent = 'Select and copy the message above.'; }
});
</script>
@endonce
