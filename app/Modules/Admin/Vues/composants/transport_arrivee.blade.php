{{--
    L'arrivée d'une livraison (chantier 15.3) : l'heure, qui a reçu, sa
    signature, les observations. C'est ce qui prouve la remise ; « marquer
    livré » d'un seul clic n'en gardait aucune trace.

    La signature se trace au doigt ou à la souris sur le cadre, et part en
    image PNG dans le champ caché. Paramètre : `action` (l'adresse du POST).
--}}
<div class="modal-overlay" id="modalArriveeLivraison">
    <div class="modal" style="max-width:520px;">
        <div class="modal-header">
            <h3><i class="fas fa-box-open"></i> Confirmer la livraison</h3>
            <button type="button" class="modal-close" data-modal-close>✕</button>
        </div>
        <form method="POST" action="{{ $action }}" id="formArriveeLivraison">
            @csrf
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Heure d'arrivée *</label>
                    <input type="datetime-local" name="heure_arrivee" class="form-control" required value="{{ now()->format('Y-m-d\TH:i') }}">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Réceptionnaire *</label>
                    <input type="text" name="receptionnaire_nom" class="form-control" required maxlength="150" placeholder="Nom de qui reçoit">
                </div>
                <div class="form-group" style="grid-column:1/-1;margin-bottom:0;">
                    <label class="form-label">Signature du réceptionnaire *</label>
                    <canvas id="signatureLivraison" width="460" height="140"
                            style="width:100%;height:140px;border:1px dashed var(--border);border-radius:8px;background:#fff;touch-action:none;"></canvas>
                    <button type="button" class="btn btn-outline btn-sm" style="margin-top:6px;" id="effacerSignatureLivraison">Effacer</button>
                    <input type="hidden" name="receptionnaire_signature" id="signatureLivraisonDonnee">
                </div>
                <div class="form-group" style="grid-column:1/-1;margin-bottom:0;">
                    <label class="form-label">Observations *</label>
                    <textarea name="observations" class="form-control" rows="2" required maxlength="1000" placeholder="« RAS » s'il n'y a rien à signaler"></textarea>
                </div>
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Confirmer la remise</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var toile = document.getElementById('signatureLivraison');
    var champ = document.getElementById('signatureLivraisonDonnee');
    if (!toile || !champ) return;
    var ctx = toile.getContext('2d'), trace = false, signe = false;
    ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#0f172a';

    function point(e) {
        var r = toile.getBoundingClientRect();
        return { x: (e.clientX - r.left) * toile.width / r.width, y: (e.clientY - r.top) * toile.height / r.height };
    }
    toile.addEventListener('pointerdown', function (e) { trace = true; var p = point(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); });
    toile.addEventListener('pointermove', function (e) { if (!trace) return; var p = point(e); ctx.lineTo(p.x, p.y); ctx.stroke(); signe = true; });
    ['pointerup', 'pointerleave'].forEach(function (t) { toile.addEventListener(t, function () { trace = false; }); });
    document.getElementById('effacerSignatureLivraison').addEventListener('click', function () {
        ctx.clearRect(0, 0, toile.width, toile.height); signe = false; champ.value = '';
    });
    // Sans trait, le champ reste vide et le serveur refuse : un cadre blanc
    // n'est pas une signature.
    document.getElementById('formArriveeLivraison').addEventListener('submit', function () {
        champ.value = signe ? toile.toDataURL('image/png') : '';
    });
})();
</script>
