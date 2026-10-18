<?php

require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require_login();
$token = csrf_token();
$dates = db()->query('SELECT DISTINCT date FROM campaigns ORDER BY date DESC')->fetchAll(PDO::FETCH_COLUMN);
session_write_close();

page_open('Campaigns', 'campaigns');
?>
  <div class="panel panel-default panel-dns">
    <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <h3 class="page-title">Campaigns</h3>
      <div class="heading-tools">
        <span class="filter-label">Layout</span>
        <div class="btn-group filter-group" id="layoutFilter">
          <button type="button" class="btn btn-default" data-value="5v5">5v5</button>
          <button type="button" class="btn btn-default" data-value="3v3">3v3</button>
        </div>
        <span class="filter-label">Skill level</span>
        <div class="btn-group filter-group" id="skillFilter">
          <button type="button" class="btn btn-default" data-value="kyber">Kyber</button>
          <button type="button" class="btn btn-default" data-value="aurodium">Aurodium</button>
        </div>
        <button type="button" class="btn btn-primary" id="addCampaign">
          <i class="fa-solid fa-plus"></i> Add new campaign
        </button>
      </div>
    </div>
    <div class="panel-body">
      <table id="campaignGrid" class="table table-condensed table-hover table-striped">
        <thead>
          <tr>
            <th data-column-id="id" data-identifier="true" data-type="numeric" data-visible="false">ID</th>
            <th data-column-id="layout" data-sortable="true" data-width="20%">Layout</th>
            <th data-column-id="skill" data-formatter="skill" data-sortable="true" data-width="25%">Skill level</th>
            <th data-column-id="date" data-sortable="true" data-order="desc" data-width="30%">Date</th>
            <th data-column-id="commands" data-formatter="commands" data-sortable="false"
                data-searchable="false" data-css-class="col-cmd" data-header-css-class="col-cmd" data-width="110px">Commands</th>
          </tr>
        </thead>
      </table>
    </div>
  </div>

  <div class="modal fade" id="campaignModal" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-label="Cancel">&times;</button>
          <h4 class="modal-title">New campaign</h4>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Layout</label>
            <div>
              <label class="radio-inline"><input type="radio" name="newLayout" value="5v5" checked> 5v5</label>
              <label class="radio-inline"><input type="radio" name="newLayout" value="3v3"> 3v3</label>
            </div>
          </div>
          <div class="form-group">
            <label>Skill level</label>
            <div>
              <label class="radio-inline"><input type="radio" name="newSkill" value="kyber" checked> Kyber</label>
              <label class="radio-inline"><input type="radio" name="newSkill" value="aurodium"> Aurodium</label>
            </div>
          </div>
          <div class="form-group">
            <label for="campaignDate">Date</label>
            <div class="input-group">
              <input type="text" id="campaignDate" class="form-control" list="campaignDates"
                     maxlength="10" autocomplete="off" inputmode="numeric" spellcheck="false"
                     placeholder="YYYY-MM-DD" value="<?= h($dates[0] ?? '') ?>">
              <datalist id="campaignDates">
                <?php foreach ($dates as $date): ?>
                  <option value="<?= h($date) ?>">
                <?php endforeach; ?>
              </datalist>
              <span class="input-group-btn">
                <button type="button" class="btn btn-default" id="todayDate">
                  <i class="fa-solid fa-calendar-day"></i> Today
                </button>
              </span>
            </div>
          </div>
          <div id="campaignError" class="text-danger" style="display:none;"></div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="campaignOk">OK</button>
        </div>
      </div>
    </div>
  </div>
<?php page_close(); ?>
<script>
var CSRF = <?= json_encode($token) ?>;
var layoutFilter = '';
var skillFilter = '';

function esc(s) {
    return String(s == null ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

var grid = $('#campaignGrid').bootgrid({
    ajax: true,
    url: 'api.php?api=campaigns',
    rowCount: [10, 25, 50, -1],
    selection: false,
    searchSettings: { delay: 200, characters: 1 },
    post: function () {
        return { csrf: CSRF, layout: layoutFilter, skill: skillFilter };
    },
    formatters: {
        skill: function (column, row) {
            return esc(row.skill_label);
        },
        commands: function (column, row) {
            return '<a class="cmd-btn" href="campaign.php?id=' + encodeURIComponent(row.id) + '" title="Edit">' +
                '<i class="fa-solid fa-pencil"></i></a>' +
                '<button type="button" class="cmd-btn cmd-danger js-delete" title="Delete" data-id="' +
                encodeURIComponent(row.id) + '"><i class="fa-regular fa-trash-can"></i></button>';
        }
    }
}).on('loaded.rs.jquery.bootgrid', function () {
    grid.find('.js-delete').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var id = $(this).data('id');
        swgohConfirm('Delete this campaign?', function () {
            $.post('api.php?api=campaign_delete', { csrf: CSRF, id: id }, null, 'json').done(function () {
                grid.bootgrid('reload');
            }).fail(function () {
                swgohSay('Could not delete the campaign.');
            });
        });
    });
    grid.find('tbody tr').on('click', function (e) {
        if ($(e.target).closest('.js-delete').length) return;
        var id = $(this).attr('data-row-id');
        if (!id) return;
        window.location.href = 'campaign.php?id=' + encodeURIComponent(id);
    });
});

$('.filter-group').on('click', 'button', function () {
    var $btn = $(this);
    var group = $btn.parent().attr('id');
    var value = $btn.hasClass('active') ? '' : $btn.data('value');
    $btn.siblings().removeClass('active');
    $btn.toggleClass('active', value !== '');
    if (group === 'layoutFilter') layoutFilter = value;
    if (group === 'skillFilter') skillFilter = value;
    grid.bootgrid('reload');
});

function todayIso() {
    var now = new Date();
    return now.getFullYear() + '-' +
        String(now.getMonth() + 1).padStart(2, '0') + '-' +
        String(now.getDate()).padStart(2, '0');
}

$('#addCampaign').on('click', function () {
    $('#campaignError').hide();
    $('#campaignDate').val(todayIso());
    $('#campaignModal').modal('show');
});

$('#campaignModal').on('shown.bs.modal', function () {
    var input = document.getElementById('campaignDate');
    input.focus();
    input.select();
});

$('#todayDate').on('click', function () {
    var input = document.getElementById('campaignDate');
    input.value = todayIso();
    input.focus();
    input.select();
});

$('#campaignOk').on('click', function () {
    var date = $.trim($('#campaignDate').val());
    $('#campaignError').hide();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
        $('#campaignError').text('Enter a date as YYYY-MM-DD, or use Today.').show();
        return;
    }
    var usedDate = false;
    $('#campaignDates option').each(function () {
        if (this.value === date) usedDate = true;
    });
    if (usedDate) {
        $('#campaignError').text('A campaign already uses ' + date + '.').show();
        return;
    }
    $.post('api.php?api=campaign_create', {
        csrf: CSRF,
        layout: $('input[name=newLayout]:checked').val(),
        skill: $('input[name=newSkill]:checked').val(),
        date: date
    }, null, 'json').done(function (res) {
        if (res && res.id) {
            window.location = 'campaign.php?id=' + encodeURIComponent(res.id);
            return;
        }
        $('#campaignError').text((res && res.error) || 'Could not create the campaign.').show();
    }).fail(function (xhr) {
        var msg = 'Could not create the campaign.';
        if (xhr.responseJSON && xhr.responseJSON.error) msg = xhr.responseJSON.error;
        $('#campaignError').text(msg).show();
    });
});
</script>
</body>
</html>
