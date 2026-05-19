<div class="page">
    <div style="display: flex; gap: 3px; margin-bottom: 8px;">
        <div class="green-header" style="flex: 1; text-align: center; font-size: 8pt; padding: 3px 5px; line-height: 1.2;">
            Commune<br><strong style="font-size: 9pt;">{{ strtoupper($bureau['commune']) }}</strong>
        </div>
        <div class="green-header" style="flex: 2; text-align: center; font-size: 8pt; padding: 3px 5px; line-height: 1.2;">
            Lieu de vote<br><strong style="font-size: 9pt;">{{ strtoupper($bureau['lieu_vote']) }}</strong>
        </div>
        <div class="green-header" style="text-align: center; font-size: 8pt; padding: 3px 5px; min-width: 80px; line-height: 1.2;">
            Bureau<br><strong style="font-size: 9pt;">{{ substr($bureau['code_bureau'], -2) }}</strong>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="num-col">N° électeur<br>N.I.N.</th>
                <th class="prenom-col">Prénom(s)</th>
                <th class="nom-col">Nom</th>
                <th class="date-col">Date et lieu de naissance</th>
                <th class="pere-col">Prénom(s) du père</th>
                <th class="mere-col">Prénom(s) et nom de la mère</th>
                <th class="emarg-col">Emargement</th>
            </tr>
        </thead>
        <tbody>
            @foreach($electeurs as $index => $e)
            <tr>
                <td class="num-col">{{ ($pageIdx * $perPage) + $index + 1 }}<br><small>{{ $e->numelec }}</small></td>
                <td class="prenom-col">{{ strtoupper($e->prenom) }}</td>
                <td class="nom-col">{{ strtoupper($e->nom) }}</td>
                <td class="date-col">{{ $e->datenaiss }}<br><small>{{ strtoupper($e->lieunaiss ?? '') }}</small></td>
                <td class="pere-col">{{ strtoupper($e->prenom_pere ?? '') }}</td>
                <td class="mere-col">{{ strtoupper($e->prenom_mere ?? '') }}<br><small>{{ strtoupper($e->nom_mere ?? '') }}</small></td>
                <td class="emarg-col"></td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="page-number">{{ $pageIdx + 1 }}/{{ $totalPages }}</div>
</div>
