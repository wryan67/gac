<?php

require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require_login();
$kind = ($_GET['kind'] ?? '') === 'ships' ? 'ships' : 'characters';
$admin = is_admin();
$token = csrf_token();
session_write_close();
$title = $kind === 'ships' ? 'Ships' : 'Characters';

page_open($title, $kind);
?>
  <div class="panel panel-default panel-dns">
    <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <h3 class="page-title"><?= h($title) ?></h3>
      <?php if ($admin): ?>
        <div class="heading-tools">
          <button type="button" class="btn btn-default" id="refreshRoster">
            <i class="fa-solid fa-rotate"></i> Refresh
          </button>
        </div>
      <?php endif; ?>
    </div>
    <div class="panel-body">
      <table id="rosterGrid" class="table table-condensed table-hover table-striped">
        <thead>
          <tr>
            <th data-column-id="id" data-identifier="true" data-visible="false" data-width="0">ID</th>
            <th data-column-id="photo" data-formatter="photo" data-sortable="false" data-searchable="false" data-width="70px">Photo</th>
            <th data-column-id="name" data-sortable="true" data-order="asc">Name</th>
            <?php if ($kind === 'ships'): ?>
              <th data-column-id="capital" data-formatter="capital" data-sortable="true" data-searchable="false" data-width="110px">Capital</th>
              <th data-column-id="captain" data-sortable="true">Captain</th>
            <?php endif; ?>
            <th data-column-id="alias" data-formatter="alias" data-sortable="true">Alias</th>
          </tr>
        </thead>
      </table>
    </div>
  </div>
<?php page_close(); ?>
<script>
var CSRF = <?= json_encode($token) ?>;
var KIND = <?= json_encode($kind) ?>;
var openName = null;
var capitalOnly = false;

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function showView(cell, revert) {
    var text = cell.find('.alias-text');
    var form = cell.find('.alias-form');
    var edit = cell.find('.edit');
    if (revert) form.find('input[name=alias]').val(text.text());
    form.attr('hidden', 'hidden');
    text.removeAttr('hidden');
    edit.removeAttr('hidden');
}

$('#refreshRoster').on('click', function () {
    var btn = $(this);
    if (btn.prop('disabled')) return;
    btn.prop('disabled', true);
    var label = btn.text();
    btn.html('<i class="fa-solid fa-rotate"></i> Refreshing…');
    $.ajax({
        url: 'api.php?api=catalog_refresh',
        method: 'POST',
        dataType: 'json',
        timeout: 170000,
        data: { csrf: CSRF, kind: KIND }
    }).done(function (res) {
        var noun = KIND === 'ships' ? 'ships' : 'characters';
        var added = res.added || [];
        var failed = res.failed || [];
        var msg = added.length
            ? ('Added ' + added.length + ' ' + noun + ': ' + added.join(', ') + '.')
            : ('No new ' + noun + '.');
        if (failed.length) msg += ' Could not download: ' + failed.join(', ') + '.';
        swgohSay(msg);
        if (added.length) grid.bootgrid('reload');
    }).fail(function (xhr) {
        var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Could not refresh from swgoh.gg.';
        swgohSay(msg);
    }).always(function () {
        btn.prop('disabled', false);
        btn.html('<i class="fa-solid fa-rotate"></i> Refresh');
    });
});

var grid = $('#rosterGrid').bootgrid({
    ajax: true,
    url: 'api.php?api=roster',
    rowCount: [25, 50, -1],
    selection: false,
    searchSettings: { delay: 200, characters: 1 },
    post: function () {
        return { csrf: CSRF, kind: KIND, capital: capitalOnly ? '1' : '' };
    },
    formatters: {
        photo: function (column, row) {
            return '<img class="roster-img" alt="" src="portrait.php?kind=' +
                encodeURIComponent(KIND) + '&name=' + encodeURIComponent(row.name) + '">';
        },
        capital: function (column, row) {
            if (!row.capital) return '';
            return '<span class="capital-yes" title="Capital ship"><i class="fa-solid fa-chess-rook"></i> Capital</span>';
        },
        alias: function (column, row) {
            var name = esc(row.name);
            var alias = esc(row.alias || '');
            return '<div class="alias-cell" data-name="' + name + '">' +
                '<span class="alias-text">' + alias + '</span>' +
                '<form class="alias-form" hidden>' +
                '<input class="form-control input-sm" name="alias" maxlength="80" value="' + alias + '" aria-label="Alias">' +
                '<button class="cmd-btn" type="submit" title="Save alias"><i class="fa-solid fa-floppy-disk"></i></button>' +
                '<button class="cmd-btn cancel" type="button" title="Cancel"><i class="fa-solid fa-xmark"></i></button>' +
                '</form>' +
                '<button class="cmd-btn edit" type="button" title="Edit alias"><i class="fa-solid fa-pencil"></i></button>' +
                '</div>';
        }
    }
}).on('loaded.rs.jquery.bootgrid', function () {
    openName = null;
    grid.find('.edit').on('click', function () {
        var cell = $(this).closest('.alias-cell');
        if (openName && openName[0] !== cell[0]) showView(openName, true);
        openName = cell;
        cell.find('.alias-text').attr('hidden', 'hidden');
        $(this).attr('hidden', 'hidden');
        cell.find('.alias-form').removeAttr('hidden');
        cell.find('input[name=alias]').trigger('focus').trigger('select');
    });
    grid.find('.cancel').on('click', function () {
        showView($(this).closest('.alias-cell'), true);
        openName = null;
    });
    grid.find('.alias-form').on('submit', function (e) {
        e.preventDefault();
        var form = $(this);
        var cell = form.closest('.alias-cell');
        $.post('alias.php', {
            csrf: CSRF,
            ajax: '1',
            kind: KIND,
            name: cell.attr('data-name'),
            alias: form.find('input[name=alias]').val()
        }, null, 'json').done(function () {
            grid.bootgrid('reload');
        });
    });
});

if (KIND === 'ships') {
    var capitalFilter = $(
        '<label class="capital-only"><input type="checkbox" id="capitalOnly"> <span>Capitol Ships Only</span></label>'
    );
    $('.bootgrid-header .actionBar').prepend(capitalFilter);
    $('#capitalOnly').on('change', function () {
        capitalOnly = this.checked;
        grid.bootgrid('reload');
    });
}

if (KIND === 'characters') {
    var searchInput = $('.bootgrid-header .search-field');
    var searchBox = searchInput.closest('.search');
    var menu = $('<div class="lookup-menu" hidden></div>');
    var searchClear = $('<button type="button" class="leader-clear" title="Clear" aria-label="Clear search"><i class="fa-solid fa-xmark"></i></button>');
    searchBox.addClass('roster-lookup');
    searchBox.parent('p.search').addClass('roster-lookup');
    searchInput.attr('id', 'rosterSearch').attr('autocomplete', 'off').attr('aria-label', 'Search characters');
    searchInput.closest('.input-group').append(searchClear);
    searchBox.append(menu);
    var searchTimer = null;

    function hideMenu() {
        menu.attr('hidden', 'hidden');
    }

    function chooseCharacter(name) {
        searchInput.val(name);
        hideMenu();
        grid.bootgrid('search', name);
    }

    function refreshMenu(q) {
        if (!q) {
            menu.empty();
            hideMenu();
            return;
        }
        $.getJSON('api.php', { api: 'suggest', kind: 'character', q: q }, function (res) {
            if (searchInput.val() !== q) return;
            menu.empty();
            $.each(res.matches || [], function (_, match) {
                var button = $('<button type="button" class="lookup-item"></button>');
                button.data('name', match.name);
                button.append($('<img>', {
                    alt: '',
                    src: 'portrait.php?kind=character&name=' + encodeURIComponent(match.name)
                }));
                var label = $('<span></span>').text(match.name);
                if (match.alias) {
                    label.append($('<span class="lookup-alias"></span>').text(' ' + match.alias));
                }
                button.append(label);
                button.on('mousedown', function (e) {
                    e.preventDefault();
                    chooseCharacter(match.name);
                });
                menu.append(button);
            });
            if (menu.children().length) menu.removeAttr('hidden');
            else hideMenu();
        });
    }

    searchInput.on('input', function () {
        clearTimeout(searchTimer);
        var q = searchInput.val();
        searchTimer = setTimeout(function () {
            refreshMenu(q);
            grid.bootgrid('search', q);
        }, 60);
    });
    searchInput.on('keydown', function (e) {
        var items = menu.find('.lookup-item');
        if (!items.length || menu.is('[hidden]')) return;
        var current = items.filter('.active');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var next = e.key === 'ArrowDown' ? current.next() : current.prev();
            if (!next.length) next = e.key === 'ArrowDown' ? items.first() : items.last();
            items.removeClass('active');
            next.addClass('active');
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var pick = current.length ? current : items.first();
            chooseCharacter(pick.data('name'));
        } else if (e.key === 'Escape') {
            hideMenu();
        }
    });
    searchInput.on('blur', hideMenu);
    searchClear.on('mousedown', function (e) {
        e.preventDefault();
        searchInput.val('');
        hideMenu();
        grid.bootgrid('search', '');
    });
}
</script>
</body>
</html>
