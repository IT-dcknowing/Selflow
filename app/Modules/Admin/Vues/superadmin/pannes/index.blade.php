@extends('admin::gabarits.application')
@section('titre', 'Pannes & assistance')
@section('topbar_titre', 'SuperAdmin — Pannes & assistance')

@section('contenu')
<div style="max-width:1200px; margin:0 auto;">

    {{-- Les contacts de l'assistance : ce que voit l'utilisateur sur la page
         de maintenance. --}}
    <div class="card" style="padding:18px 20px; margin-bottom:18px;">
        <h3 style="font-size:15px; font-weight:800; margin-bottom:4px;"><i class="fas fa-headset" style="color:var(--primary);"></i> Contacts de l'assistance et du service commercial</h3>
        <p style="font-size:12.5px; color:var(--text-3); margin-bottom:14px;">L'assistance s'affiche sur la page « en maintenance » ; le service commercial sur l'offre « sur mesure » (à défaut, l'assistance).</p>
        <form method="POST" action="{{ route('superadmin.pannes.assistance') }}" style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items:end;">
            @csrf
            <div class="form-group" style="margin:0;">
                <label class="form-label">Courriel</label>
                <input type="email" name="assistance_email" class="form-control" value="{{ old('assistance_email', $assistance['email']) }}" placeholder="support@dc-knowing.com">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Téléphone</label>
                <input type="text" name="assistance_telephone" class="form-control" value="{{ old('assistance_telephone', $assistance['telephone']) }}" placeholder="+225 07 00 00 00 00">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Service commercial — courriel <small style="color:var(--text-3);">(offre sur mesure)</small></label>
                <input type="email" name="commercial_email" class="form-control" value="{{ old('commercial_email', $assistance['commercial_email']) }}" placeholder="commercial@dc-knowing.com">
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Service commercial — téléphone</label>
                <input type="text" name="commercial_telephone" class="form-control" value="{{ old('commercial_telephone', $assistance['commercial_telephone']) }}" placeholder="À défaut : ceux de l'assistance">
            </div>
            <div class="form-group" style="margin:0; grid-column:1 / -1;">
                <label class="form-label">Numéros de paiement du cabinet <small style="color:var(--text-3);">(un par ligne — stickers et abonnements)</small></label>
                <textarea name="paiement_numeros" class="form-control" rows="3" placeholder="Wave : 07 00 00 00 00&#10;Orange Money : 07 00 00 00 01">{{ old('paiement_numeros', $assistance['paiement_numeros'] ?? '') }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </form>
    </div>

    <div class="card" style="padding:14px 20px; margin-bottom:14px; font-size:13px; color:var(--text-2); line-height:1.7;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        Chaque ligne est un défaut, avec le nombre de fois où il s'est produit. Cliquez sur le code pour voir l'erreur.
        Une panne <strong>corrigée quitte la liste</strong> : à la main, ou d'elle-même quand le fichier fautif a été
        modifié depuis (correctif déployé). Si elle se reproduit, elle revient.
        <strong>{{ $pannes->count() }}</strong> ouverte{{ $pannes->count() > 1 ? 's' : '' }} · {{ $corrigees }} corrigée{{ $corrigees > 1 ? 's' : '' }} ces 7 derniers jours.
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        @if($pannes->isEmpty())
            <p style="padding:30px; text-align:center; color:var(--text-3);"><i class="fas fa-circle-check" style="color:var(--success);"></i> Aucune panne ouverte.</p>
        @else
        <table class="table" style="width:100%;">
            <thead><tr><th>Code</th><th>Adresse</th><th>Fois</th><th>Dernière</th><th></th></tr></thead>
            <tbody>
            @foreach($pannes as $panne)
                <tr class="ligne-panne" data-panne="{{ $panne->id }}">
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn btn-outline btn-sm code-panne" onclick="basculerPanne({{ $panne->id }})"
                                style="font-family:ui-monospace,Menlo,monospace; font-weight:800; color:var(--danger);">
                            {{ $panne->code }}
                        </button>
                    </td>
                    <td style="font-size:12.5px; word-break:break-all;">
                        <span style="color:var(--text-3); font-weight:700;">{{ $panne->methode }}</span> {{ $panne->url ?? '—' }}
                    </td>
                    <td><strong>{{ $panne->occurrences }}</strong></td>
                    <td style="font-size:12px; white-space:nowrap;">{{ $panne->derniere_le?->format('d/m/Y H:i') }}</td>
                    <td style="text-align:right;">
                        <form method="POST" action="{{ route('superadmin.pannes.corriger', $panne) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Corrigée</button>
                        </form>
                    </td>
                </tr>
                <tr id="detail-panne-{{ $panne->id }}" class="detail-panne" hidden>
                    <td colspan="5" style="background:var(--bg); padding:16px 20px;">
                        <dl style="display:grid; grid-template-columns:140px 1fr; gap:6px 14px; font-size:12.5px;">
                            <dt style="color:var(--text-3); font-weight:700;">Erreur</dt><dd><strong>{{ $panne->message ?: '—' }}</strong></dd>
                            <dt style="color:var(--text-3); font-weight:700;">Type</dt><dd style="font-family:ui-monospace,Menlo,monospace;">{{ $panne->type }}</dd>
                            <dt style="color:var(--text-3); font-weight:700;">Fichier</dt><dd style="font-family:ui-monospace,Menlo,monospace;">{{ $panne->fichier }}:{{ $panne->ligne }}</dd>
                            <dt style="color:var(--text-3); font-weight:700;">Entreprise</dt><dd>{{ $panne->entreprise?->nom ?? '—' }}</dd>
                            <dt style="color:var(--text-3); font-weight:700;">Utilisateur</dt><dd>{{ $panne->utilisateur ? trim($panne->utilisateur->prenom . ' ' . $panne->utilisateur->nom) . ' — ' . $panne->utilisateur->email : '—' }}</dd>
                            <dt style="color:var(--text-3); font-weight:700;">Première fois</dt><dd>{{ $panne->premiere_le?->format('d/m/Y H:i:s') }}</dd>
                        </dl>
                        @if($panne->trace)
                            <pre style="margin-top:12px; padding:14px; background:#0F172A; color:#CBD5E1; border-radius:9px; font-size:11.5px; line-height:1.6; max-height:340px; overflow:auto;">{{ $panne->trace }}</pre>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

<script>
function basculerPanne(id) {
    var ligne = document.getElementById('detail-panne-' + id);
    if (ligne) ligne.hidden = !ligne.hidden;
}
</script>
@endsection
