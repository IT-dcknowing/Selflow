{{--
    Le règlement saisi au moment de facturer une commande — vente ou achat.

    Recette du 08/10/2026 : « Valider & Facturer » partait d'un seul clic, et
    le règlement se devinait. Côté achat, une commande sans mode était
    facturée « Payé » et la caisse décaissée d'un paiement que personne
    n'avait saisi. Le mode et le montant se demandent ; sans réponse, la
    pièce part à crédit.

    Paramètres : $idModale, $action, $titre, $montant (proposé), $modeDefaut
    ('Caisse', 'Banque' ou 'Crédit'), $banques (journaux de banque), $libelleMontant.
--}}
@php
    $modeDefaut = in_array($modeDefaut ?? 'Crédit', ['Caisse', 'Banque', 'Crédit'], true) ? $modeDefaut : 'Crédit';
@endphp
<div id="{{ $idModale }}" class="no-print"
    style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:9999; align-items:center; justify-content:center; overflow-y:auto; padding:20px;">
    <div style="background:#fff; border-radius:14px; max-width:480px; width:100%; padding:28px; box-shadow:0 20px 60px rgba(0,0,0,.2); margin:auto;">
        <h3 style="font-size:18px; font-weight:800; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
            <i class="fas fa-file-invoice-dollar" style="color:#047857;"></i> {{ $titre }}
        </h3>
        <p style="font-size:13px; color:#6b7280; margin-bottom:20px;">Indiquez le règlement reçu à la facturation. Sans règlement, la facture part à crédit.</p>

        <form method="POST" action="{{ $action }}" style="margin:0;" data-reglement="{{ $idModale }}">
            @csrf
            <div style="margin-bottom:18px;">
                <label style="font-weight:700; font-size:12px; text-transform:uppercase; color:#475569; display:block; margin-bottom:6px;">Mode de paiement <span style="color:#dc2626">*</span></label>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px;">
                    @foreach(['Caisse', 'Banque', 'Crédit'] as $mode)
                        <label style="border:1px solid #e2e5ec; border-radius:8px; padding:10px; cursor:pointer; text-align:center;">
                            <input type="radio" name="mode_paiement" value="{{ $mode }}" {{ $mode === $modeDefaut ? 'checked' : '' }}
                                onchange="reglementChoisirMode('{{ $idModale }}', this.value)">
                            <span style="font-size:12.5px; font-weight:700; color:#475569;">{{ $mode }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div data-bloc="banque" style="display:{{ $modeDefaut === 'Banque' ? 'block' : 'none' }}; background:#f8fafc; border:1px solid #e2e5ec; border-radius:10px; padding:16px; margin-bottom:18px;">
                <div style="margin-bottom:12px;">
                    <label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Banque *</label>
                    <select name="banque_id" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;">
                        <option value="">— Choisir la banque —</option>
                        @foreach(($banques ?? collect()) as $b)
                            <option value="{{ $b->id }}">{{ $b->intitule }} ({{ $b->code }})</option>
                        @endforeach
                    </select>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Moyen de paiement bancaire *</label>
                    <select name="moyen_bancaire" style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px; background:#fff;">
                        <option value="">— Choisir le moyen —</option>
                        <option value="carte">Carte bancaire</option>
                        <option value="virement">Virement</option>
                        <option value="cheque">Chèque</option>
                    </select>
                </div>
                <div>
                    <label style="font-weight:600; font-size:12px; display:block; margin-bottom:4px;">Référence *</label>
                    <input type="text" name="reference_paiement" placeholder="Numéro..." style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:6px;">
                </div>
            </div>

            <div data-bloc="montant" style="margin-bottom:18px; display:{{ $modeDefaut === 'Crédit' ? 'none' : 'block' }};">
                <label style="font-weight:700; font-size:12px; text-transform:uppercase; color:#475569; display:block; margin-bottom:6px;">{{ $libelleMontant ?? 'Montant réglé' }}</label>
                <input type="number" step="any" min="0" name="montant_paye" value="{{ round((float) $montant) }}" {{ $modeDefaut === 'Crédit' ? 'disabled' : '' }}
                    style="width:100%; padding:10px 14px; border:1px solid #cbd5e1; border-radius:8px; font-size:14px; font-weight:700;">
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="document.getElementById('{{ $idModale }}').style.display='none'"
                    style="padding:9px 18px; border-radius:8px; border:1px solid #cbd5e1; background:#fff; font-weight:600; cursor:pointer;">Annuler</button>
                <button type="submit"
                    style="padding:9px 18px; border-radius:8px; background:#047857; color:#fff; border:none; font-weight:700; cursor:pointer;">
                    <i class="fas fa-check"></i> Créer la facture
                </button>
            </div>
        </form>
    </div>
</div>
<script>
function reglementChoisirMode(idModale, mode) {
    var modale = document.getElementById(idModale);
    if (!modale) return;
    modale.querySelector('[data-bloc="banque"]').style.display = mode === 'Banque' ? 'block' : 'none';
    var blocMontant = modale.querySelector('[data-bloc="montant"]');
    blocMontant.style.display = mode === 'Crédit' ? 'none' : 'block';
    blocMontant.querySelector('input').disabled = mode === 'Crédit';
}
</script>
