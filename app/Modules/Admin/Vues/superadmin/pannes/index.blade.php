@extends('admin::gabarits.application')
@section('titre', 'Pannes & assistance')
@section('topbar_titre', 'SuperAdmin — Pannes & assistance')

@section('contenu')
<div style="max-width:1200px; margin:0 auto;">

    {{-- Mise hors ligne (16/10/2026) : attaque, gros travaux. Les
         superadministrateurs gardent l'accès pour rallumer. --}}
    <div class="card" style="padding:18px 20px; margin-bottom:18px; border-left:4px solid {{ $horsLigne ? '#dc2626' : '#16a34a' }};">
        <div style="display:flex; justify-content:space-between; gap:16px; align-items:flex-start; flex-wrap:wrap;">
            <div style="flex:1; min-width:280px;">
                <h3 style="font-size:15px; font-weight:800; margin-bottom:4px;">
                    <i class="fas fa-power-off" style="color:{{ $horsLigne ? '#dc2626' : '#16a34a' }};"></i>
                    @if($horsLigne)
                        Selflow est <span style="color:#dc2626;">hors ligne</span>
                    @else
                        Selflow est <span style="color:#16a34a;">en ligne</span>
                    @endif
                </h3>
                <p style="font-size:12.5px; color:var(--text-3); line-height:1.6;">
                    @if($horsLigne)
                        Depuis le {{ $horsLigne['depuis'] ? \Illuminate\Support\Carbon::parse($horsLigne['depuis'])->format('d/m/Y à H:i') : '—' }}{{ $horsLigne['par'] ? ' par ' . $horsLigne['par'] : '' }}.
                        Les utilisateurs voient la page « maintenance évolutive, ne vous inquiétez pas » avec les contacts ci-dessous ; l'API répond 503.
                    @else
                        En cas d'attaque ou de gros travaux, coupez l'accès : les utilisateurs verront une page rassurante
                        (« maintenance évolutive, ne vous inquiétez pas ») avec le téléphone et le courriel de l'assistance.
                        Les superadministrateurs gardent l'accès.
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('superadmin.pannes.hors_ligne') }}" style="margin:0; display:flex; flex-direction:column; gap:8px; min-width:280px;"
                  onsubmit="return confirm(@js($horsLigne ? 'Remettre Selflow en ligne pour tous ?' : 'Mettre Selflow hors ligne pour tous les utilisateurs ?'));">
                @csrf
                @if($horsLigne)
                    <input type="hidden" name="action" value="rallumer">
                    <button type="submit" class="btn" style="background:#16a34a; color:#fff; border:none;"><i class="fas fa-power-off"></i> Remettre en ligne</button>
                @else
                    <input type="hidden" name="action" value="couper">
                    <label class="form-label" style="margin:0;">Message affiché aux utilisateurs <small style="color:var(--text-3);">(facultatif)</small></label>
                    <input type="text" name="message" class="form-control" maxlength="500" placeholder="Ex. : retour prévu vers 14 h">
                    <button type="submit" class="btn" style="background:#dc2626; color:#fff; border:none;"><i class="fas fa-power-off"></i> Mettre hors ligne</button>
                @endif
            </form>
        </div>

        {{-- Hors ligne : le message actuel reste visible et modifiable (16/10/2026). --}}
        @if($horsLigne)
        <form method="POST" action="{{ route('superadmin.pannes.hors_ligne') }}" style="margin:12px 0 0; display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap;">
            @csrf
            <input type="hidden" name="action" value="couper">
            <div class="form-group" style="margin:0; flex:1; min-width:280px;">
                <label class="form-label">Message affiché aux utilisateurs</label>
                <input type="text" name="message" class="form-control" maxlength="500"
                       value="{{ $horsLigne['message'] ?? '' }}" placeholder="Ex. : retour prévu vers 14 h">
            </div>
            <button type="submit" class="btn btn-outline"><i class="fas fa-pen"></i> Mettre à jour le message</button>
        </form>
        @endif
    </div>

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
            <div class="form-group" style="margin:0; grid-column:1 / -1; border-top:1px solid var(--border); padding-top:14px;">
                <h4 style="font-size:13.5px; font-weight:800; margin-bottom:4px;"><i class="fas fa-bell" style="color:var(--primary);"></i> Alertes de la plateforme — qui reçoit quoi</h4>
                <p style="font-size:12px; color:var(--text-3); line-height:1.6;">
                    Une création d'entreprise, une demande de stickers, un paiement reçu, une panne ou une attaque
                    déclenche une alerte. Indiquez les destinataires ci-dessous.
                </p>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Courriels des alertes <small style="color:var(--text-3);">(un par ligne)</small></label>
                <textarea name="alertes_emails" class="form-control" rows="3" placeholder="meledjeagnimel17@gmail.com&#10;it.dcknowing@gmail.com">{{ old('alertes_emails', $assistance['alertes_emails'] ?? '') }}</textarea>
            </div>
            <div class="form-group" style="margin:0;">
                <label class="form-label">Numéros WhatsApp des alertes <small style="color:var(--text-3);">(un par ligne)</small></label>
                <textarea name="alertes_whatsapp" class="form-control" rows="3" placeholder="+225 07 67 13 19 93&#10;+225 01 70 51 89 67">{{ old('alertes_whatsapp', $assistance['alertes_whatsapp'] ?? '') }}</textarea>
            </div>
            <div class="form-group" style="margin:0; grid-column:1 / -1;">
                <label class="form-label">Relais WhatsApp — URL <small style="color:var(--text-3);">(API Meta, Twilio ou pont auto-hébergé)</small></label>
                <input type="text" name="alertes_whatsapp_webhook" class="form-control" value="{{ old('alertes_whatsapp_webhook', $assistance['alertes_whatsapp_webhook'] ?? '') }}" placeholder="https://… — à défaut, les alertes WhatsApp sont journalisées">
                <small style="color:var(--text-3); font-size:11px;">WhatsApp ne s'envoie pas directement depuis le serveur : sans relais, le courriel reste le canal principal.</small>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Enregistrer</button>
        </form>
    </div>

    <div class="card" style="padding:14px 20px; margin-bottom:14px; font-size:13px; color:var(--text-2); line-height:1.7;">
        <i class="fas fa-circle-info" style="color:var(--primary);"></i>
        Chaque ligne est un défaut, avec le nombre de fois où il s'est produit. Cliquez sur le code pour voir l'erreur.
        Une panne <strong>corrigée passe dans l'onglet « Corrigées »</strong> : à la main, ou d'elle-même quand le
        fichier fautif a été modifié depuis (correctif déployé). Si elle se reproduit, elle revient côté « Ouvertes ».
    </div>

    <div style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
        <a href="{{ route('superadmin.pannes.index', ['onglet' => 'ouvertes']) }}"
           class="btn btn-sm {{ $onglet === 'ouvertes' ? 'btn-primary' : 'btn-outline' }}">
            <i class="fas fa-triangle-exclamation"></i> Ouvertes <strong>{{ $nbOuvertes }}</strong>
        </a>
        <a href="{{ route('superadmin.pannes.index', ['onglet' => 'corrigees']) }}"
           class="btn btn-sm {{ $onglet === 'corrigees' ? 'btn-primary' : 'btn-outline' }}">
            <i class="fas fa-circle-check"></i> Corrigées <strong>{{ $nbCorrigees }}</strong>
        </a>
    </div>

    <div class="card" style="padding:0; overflow-x:auto;">
        @if($pannes->isEmpty())
            <p style="padding:30px; text-align:center; color:var(--text-3);"><i class="fas fa-circle-check" style="color:var(--success);"></i> {{ $onglet === 'ouvertes' ? 'Aucune panne ouverte.' : 'Aucune panne corrigée.' }}</p>
        @else
        <table class="table" style="width:100%;">
            <thead><tr><th>Code</th><th>Adresse</th><th>Fois</th><th>{{ $onglet === 'ouvertes' ? 'Dernière' : 'Corrigée le' }}</th><th></th></tr></thead>
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
                    <td style="font-size:12px; white-space:nowrap;">
                        @if($onglet === 'ouvertes')
                            {{ $panne->derniere_le?->format('d/m/Y H:i') }}
                        @else
                            {{ $panne->corrigee_le?->format('d/m/Y H:i') }}
                            @if($panne->corrigee_par)<div style="font-size:11px; color:var(--text-3);">{{ $panne->corrigee_par }}</div>@endif
                        @endif
                    </td>
                    <td style="text-align:right;">
                        @if($onglet === 'ouvertes')
                        <form method="POST" action="{{ route('superadmin.pannes.corriger', $panne) }}" style="display:inline;">
                            @csrf
                            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check"></i> Corrigée</button>
                        </form>
                        @else
                        <span class="badge" style="background:#ecfdf5; color:#047857; font-size:11px; padding:4px 8px; border-radius:6px; font-weight:700;"><i class="fas fa-circle-check"></i> Corrigée</span>
                        @endif
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
        <div style="padding:12px 16px;">{{ $pannes->links() }}</div>
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
