/* Admin page "Beiträge > PubMed (KornSW)": list filtering / selection and the live search of the tab "Manuell hinzufügen". */
(function () {
  'use strict';

  var config = window.kornswPmcConfig || {};

  /* ------------------------------------------------------------------ helpers */

  function escapeRegExp(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function splitTokens(input) {
    return input.split(/[\s,;]+/).filter(function (token) {
      return token.length > 0;
    });
  }

  /* Rebuilds the element from its original text, wrapping matches in <mark> (text nodes only, no innerHTML). */
  function highlightElement(element, pattern) {
    if (!element.hasAttribute('data-original')) {
      element.setAttribute('data-original', element.textContent);
    }
    var text = element.getAttribute('data-original');
    element.textContent = '';
    if (pattern === null) {
      element.textContent = text;
      return;
    }
    var lastIndex = 0;
    var match;
    pattern.lastIndex = 0;
    while ((match = pattern.exec(text)) !== null) {
      if (match[0].length === 0) {
        pattern.lastIndex++;
        continue;
      }
      element.appendChild(document.createTextNode(text.substring(lastIndex, match.index)));
      var mark = document.createElement('mark');
      mark.textContent = match[0];
      element.appendChild(mark);
      lastIndex = match.index + match[0].length;
    }
    element.appendChild(document.createTextNode(text.substring(lastIndex)));
  }

  function createCell(row, className, text) {
    var cell = document.createElement('td');
    if (className) {
      cell.className = className;
    }
    if (text !== undefined) {
      cell.textContent = text;
    }
    row.appendChild(cell);
    return cell;
  }

  function updateTabCount(tabName, count) {
    document.querySelectorAll('.nav-tab').forEach(function (tab) {
      if (tab.href.indexOf('tab=' + tabName) !== -1) {
        tab.textContent = tab.textContent.replace(/\(\d+\)/, '(' + count + ')');
      }
    });
  }

  /* ------------------------------------------------------------------ list / excluded list */

  function initItemsTab(form) {
    var tab = form.closest('.kornsw-pmc-tab');
    var filterInput = tab.querySelector('.kornsw-pmc-filter');
    var filterCount = tab.querySelector('.kornsw-pmc-filter-count');
    var rows = Array.prototype.slice.call(form.querySelectorAll('tbody tr'));
    var selectAll = form.querySelector('.kornsw-pmc-select-all');
    var selectedCount = form.querySelector('.kornsw-pmc-selected-count');

    function updateSelection() {
      var count = 0;
      rows.forEach(function (row) {
        var checkbox = row.querySelector('.kornsw-pmc-select');
        row.classList.toggle('is-selected', checkbox.checked);
        if (checkbox.checked) {
          count++;
        }
      });
      selectedCount.textContent = String(count);
    }

    /* A row matches if it contains every token (PMID or any word of title, authors, journal). */
    function applyFilter() {
      var tokens = splitTokens(filterInput.value);
      var pattern = null;
      if (tokens.length > 0) {
        pattern = new RegExp('(' + tokens.map(escapeRegExp).join('|') + ')', 'gi');
      }
      var visibleCount = 0;
      rows.forEach(function (row) {
        var text = row.getAttribute('data-pmid') + ' ' + row.textContent;
        var isVisible = tokens.every(function (token) {
          return text.toLowerCase().indexOf(token.toLowerCase()) !== -1;
        });
        row.hidden = !isVisible;
        if (isVisible) {
          visibleCount++;
        }
        row.querySelectorAll('.kornsw-pmc-hl').forEach(function (element) {
          highlightElement(element, pattern);
        });
      });
      filterCount.textContent = visibleCount + ' von ' + rows.length + ' Einträgen';
    }

    selectAll.addEventListener('change', function () {
      rows.forEach(function (row) {
        if (!row.hidden) {
          row.querySelector('.kornsw-pmc-select').checked = selectAll.checked;
        }
      });
      updateSelection();
    });
    rows.forEach(function (row) {
      row.querySelector('.kornsw-pmc-select').addEventListener('change', updateSelection);
    });
    filterInput.addEventListener('input', applyFilter);

    /* All selected PMIDs travel as one JSON field (avoids max_input_vars limits). */
    form.addEventListener('submit', function (event) {
      var pmids = rows.filter(function (row) {
        return row.querySelector('.kornsw-pmc-select').checked;
      }).map(function (row) {
        return row.getAttribute('data-pmid');
      });
      if (pmids.length === 0) {
        event.preventDefault();
        window.alert('Bitte zuerst Einträge auswählen.');
        return;
      }
      form.elements.pmids_json.value = JSON.stringify(pmids);
    });

    applyFilter();

    /* Deep link from the review mail (&pmid=...): select the entry and scroll to it */
    if (filterInput.value !== '') {
      rows.forEach(function (row) {
        if (!row.hidden && splitTokens(filterInput.value).indexOf(row.getAttribute('data-pmid')) !== -1) {
          row.querySelector('.kornsw-pmc-select').checked = true;
          row.classList.add('is-focused');
          row.scrollIntoView({ block: 'center' });
        }
      });
    }
    updateSelection();
  }

  /* ------------------------------------------------------------------ query builder */

  var BUILDER_FIELDS = [
    { tag: 'fau', label: 'Autor (voller Name)', kind: 'phrase', placeholder: 'Korn Stephanie' },
    { tag: 'au', label: 'Autor (Initialen)', kind: 'phrase', placeholder: 'Korn S' },
    { tag: '1au', label: 'Erstautor', kind: 'phrase', placeholder: 'Korn S' },
    { tag: 'lastau', label: 'Letztautor', kind: 'phrase', placeholder: 'Korn S' },
    { tag: 'ti', label: 'Titel enthält Wörter', kind: 'words', placeholder: 'asthma biologic' },
    { tag: 'tiab', label: 'Titel/Abstract enthält Wörter', kind: 'words', placeholder: 'asthma' },
    { tag: 'all', label: 'Alle Felder enthalten Wörter', kind: 'words', placeholder: 'dupilumab' },
    { tag: 'ad', label: 'Affiliation (Institution, Ort)', kind: 'phrase', placeholder: 'Mainz' },
    { tag: 'cn', label: 'Konsortium', kind: 'phrase', placeholder: 'ATLAS Collaboration' },
    { tag: 'ta', label: 'Journal', kind: 'phrase', placeholder: 'J Clin Med' },
    { tag: 'pt', label: 'Publikationstyp', kind: 'phrase', placeholder: 'Review' },
    { tag: 'dp', label: 'Jahr (von-bis)', kind: 'years', placeholder: '2015-2026' },
    { tag: 'pmid', label: 'PMID', kind: 'pmid', placeholder: '42897285' }
  ];

  var BUILDER_OPERATORS = [
    { value: 'AND', label: 'UND' },
    { value: 'OR', label: 'ODER' },
    { value: 'NOT', label: 'UND NICHT' }
  ];

  function findField(tag) {
    return BUILDER_FIELDS.filter(function (field) {
      return field.tag === tag;
    })[0];
  }

  /* One value of a builder row as PubMed syntax, or '' if it is not usable. */
  function buildValueQuery(field, value) {
    value = value.replace(/["\[\]]/g, '').trim();
    if (value === '') {
      return '';
    }
    if (field.kind === 'words') {
      var words = value.split(/\s+/).map(function (word) {
        return word + '[' + field.tag + ']';
      });
      if (words.length === 1) {
        return words[0];
      }
      return '(' + words.join(' AND ') + ')';
    }
    if (field.kind === 'years') {
      var years = value.match(/\d{4}/g);
      if (!years) {
        return '';
      }
      if (years.length === 1) {
        return years[0] + '[dp]';
      }
      return years[0] + ':' + years[1] + '[dp]';
    }
    if (field.kind === 'pmid') {
      if (!/^\d+$/.test(value)) {
        return '';
      }
      return value + '[pmid]';
    }
    return '"' + value + '"[' + field.tag + ']';
  }

  /* Comma separated values of a row are combined with OR. */
  function buildRowQuery(row) {
    var field = findField(row.querySelector('.kornsw-pmc-builder-field').value);
    var parts = row.querySelector('.kornsw-pmc-builder-value').value.split(',').map(function (value) {
      return buildValueQuery(field, value);
    }).filter(function (part) {
      return part !== '';
    });
    if (parts.length === 0) {
      return '';
    }
    if (parts.length === 1) {
      return parts[0];
    }
    return '(' + parts.join(' OR ') + ')';
  }

  /* PubMed evaluates from left to right, which matches "top to bottom" of the rows. */
  function buildBuilderQuery(container) {
    var query = '';
    container.querySelectorAll('.kornsw-pmc-builder-row').forEach(function (row) {
      var rowQuery = buildRowQuery(row);
      if (rowQuery === '') {
        return;
      }
      if (query === '') {
        query = rowQuery;
      } else {
        query += ' ' + row.querySelector('.kornsw-pmc-builder-operator').value + ' ' + rowQuery;
      }
    });
    return query;
  }

  function createSelect(className, options) {
    var select = document.createElement('select');
    select.className = className;
    options.forEach(function (option) {
      var element = document.createElement('option');
      element.value = option.value;
      element.textContent = option.label;
      select.appendChild(element);
    });
    return select;
  }

  function addBuilderRow(container, tag, value, onChange) {
    var row = document.createElement('div');
    row.className = 'kornsw-pmc-builder-row';

    var operator = createSelect('kornsw-pmc-builder-operator', BUILDER_OPERATORS);
    var field = createSelect('kornsw-pmc-builder-field', BUILDER_FIELDS.map(function (definition) {
      return { value: definition.tag, label: definition.label };
    }));
    field.value = tag;
    var input = document.createElement('input');
    input.type = 'text';
    input.className = 'regular-text kornsw-pmc-builder-value';
    input.value = value;
    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'button-link kornsw-pmc-builder-remove';
    remove.textContent = 'entfernen';

    function updatePlaceholder() {
      input.placeholder = findField(field.value).placeholder;
    }

    operator.addEventListener('change', onChange);
    field.addEventListener('change', function () {
      updatePlaceholder();
      onChange();
    });
    input.addEventListener('input', onChange);
    remove.addEventListener('click', function () {
      row.remove();
      onChange();
    });

    row.appendChild(operator);
    row.appendChild(field);
    row.appendChild(input);
    row.appendChild(remove);
    container.appendChild(row);
    updatePlaceholder();
  }

  /* ------------------------------------------------------------------ live search */

  function initSearchTab(root) {
    var modeRadios = root.querySelectorAll('input[name="kornsw-pmc-mode"]');
    var panels = root.querySelectorAll('.kornsw-pmc-mode-panel');
    var idsInput = root.querySelector('.kornsw-pmc-ids');
    var rawInput = root.querySelector('.kornsw-pmc-raw');
    var builderRows = root.querySelector('.kornsw-pmc-builder-rows');
    var builderPreview = root.querySelector('.kornsw-pmc-builder-preview');
    var statusText = root.querySelector('.kornsw-pmc-search-status');
    var infoBox = root.querySelector('.kornsw-pmc-search-info');
    var resultsTable = root.querySelector('.kornsw-pmc-results');
    var resultsBody = resultsTable.querySelector('tbody');
    var resultsActions = root.querySelector('.kornsw-pmc-results-actions');
    var moreButton = root.querySelector('.kornsw-pmc-search-more');
    var addButton = root.querySelector('.kornsw-pmc-add-selected');
    var addStatus = root.querySelector('.kornsw-pmc-add-status');
    var selectAll = resultsTable.querySelector('.kornsw-pmc-select-all');
    var selectedCount = addButton.querySelector('.kornsw-pmc-selected-count');

    var requestSequence = 0;
    var debounceTimer = null;
    var currentTerm = '';
    var nextOffset = 0;

    function currentMode() {
      var mode = 'raw';
      modeRadios.forEach(function (radio) {
        if (radio.checked) {
          mode = radio.value;
        }
      });
      return mode;
    }

    function buildTerm() {
      var mode = currentMode();
      if (mode === 'ids') {
        return splitTokens(idsInput.value).filter(function (token) {
          return /^\d+$/.test(token);
        }).map(function (pmid) {
          return pmid + '[pmid]';
        }).join(' OR ');
      }
      if (mode === 'builder') {
        return buildBuilderQuery(builderRows);
      }
      return rawInput.value.replace(/\s+/g, ' ').trim();
    }

    function scheduleSearch() {
      builderPreview.textContent = buildBuilderQuery(builderRows) || '(leer)';
      window.clearTimeout(debounceTimer);
      debounceTimer = window.setTimeout(function () {
        runSearch(0);
      }, 900);
    }

    function post(action, fields) {
      var body = new FormData();
      body.append('action', action);
      body.append('_ajax_nonce', config.nonce);
      body.append('collection', config.collectionId);
      Object.keys(fields).forEach(function (key) {
        body.append(key, fields[key]);
      });
      return window.fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) {
        return response.json();
      });
    }

    function renderInfo(data) {
      infoBox.textContent = '';
      var summary = document.createElement('p');
      var shownTo = Math.min(data.nextOffset, data.totalCount);
      summary.textContent = data.totalCount + ' Treffer bei PubMed, angezeigt die neuesten 1-' + shownTo + '. So hat PubMed die Query verstanden:';
      var translation = document.createElement('pre');
      translation.textContent = data.translation;
      infoBox.appendChild(summary);
      infoBox.appendChild(translation);
      data.warnings.forEach(function (warning) {
        var line = document.createElement('p');
        line.className = 'kornsw-pmc-error';
        line.textContent = '⚠ PubMed meldet: ' + warning;
        infoBox.appendChild(line);
      });
      if (data.skipped.length > 0) {
        var skipped = document.createElement('p');
        skipped.className = 'kornsw-pmc-error';
        skipped.textContent = 'Nicht ladbar (PubMed-Fehler): ' + data.skipped.join(', ');
        infoBox.appendChild(skipped);
      }
      infoBox.hidden = false;
    }

    var STATUS_LABELS = { 'new': 'Neu', listed: 'In der Liste', excluded: 'Ausgeschlossen' };

    function renderHits(hits) {
      hits.forEach(function (hit) {
        var row = document.createElement('tr');
        row.setAttribute('data-pmid', hit.pmid);
        row.className = 'status-' + hit.status;

        var selectCell = createCell(row, 'col-select');
        var checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.className = 'kornsw-pmc-select';
        checkbox.value = hit.pmid;
        checkbox.disabled = hit.status === 'listed';
        checkbox.addEventListener('change', updateSelection);
        selectCell.appendChild(checkbox);

        var titleCell = createCell(row, '');
        var link = document.createElement('a');
        link.href = 'https://pubmed.ncbi.nlm.nih.gov/' + encodeURIComponent(hit.pmid) + '/';
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = hit.title;
        titleCell.appendChild(link);

        createCell(row, 'authors', hit.authors);
        createCell(row, 'citation', hit.citation);
        createCell(row, 'origin', STATUS_LABELS[hit.status] || hit.status);
        createCell(row, 'pmid', hit.pmid);
        resultsBody.appendChild(row);
      });
    }

    function updateSelection() {
      var count = resultsBody.querySelectorAll('.kornsw-pmc-select:checked').length;
      selectedCount.textContent = String(count);
      addButton.disabled = count === 0;
    }

    function runSearch(offset) {
      var term = buildTerm();
      var sequence = ++requestSequence;
      if (term === '') {
        statusText.textContent = 'Bitte Suchkriterien eingeben.';
        return;
      }
      currentTerm = term;
      statusText.textContent = 'Suche läuft …';
      moreButton.disabled = true;

      post('kornsw_pmc_search', { term: term, offset: String(offset) }).then(function (response) {
        if (sequence !== requestSequence) {
          return; /* a newer search was started meanwhile */
        }
        if (!response.success) {
          statusText.textContent = response.data && response.data.message ? response.data.message : 'Suche fehlgeschlagen.';
          return;
        }
        var data = response.data;
        if (offset === 0) {
          resultsBody.textContent = '';
          selectAll.checked = false;
        }
        renderInfo(data);
        renderHits(data.hits);
        nextOffset = data.nextOffset;
        resultsTable.hidden = data.hits.length === 0 && offset === 0;
        resultsActions.hidden = resultsTable.hidden;
        moreButton.hidden = nextOffset >= data.totalCount;
        moreButton.disabled = false;
        statusText.textContent = '';
        updateSelection();
      }).catch(function (error) {
        if (sequence === requestSequence) {
          statusText.textContent = 'Suche fehlgeschlagen: ' + error;
        }
      });
    }

    function addSelected() {
      var pmids = Array.prototype.map.call(resultsBody.querySelectorAll('.kornsw-pmc-select:checked'), function (checkbox) {
        return checkbox.value;
      });
      if (pmids.length === 0) {
        return;
      }
      addButton.disabled = true;
      addStatus.textContent = 'Wird übernommen …';
      post('kornsw_pmc_add', { pmids: JSON.stringify(pmids), term: currentTerm }).then(function (response) {
        if (!response.success) {
          addStatus.textContent = response.data && response.data.message ? response.data.message : 'Übernahme fehlgeschlagen.';
          updateSelection();
          return;
        }
        response.data.addedPmids.forEach(function (pmid) {
          var row = resultsBody.querySelector('tr[data-pmid="' + pmid + '"]');
          if (row) {
            row.className = 'status-listed';
            row.querySelector('.origin').textContent = STATUS_LABELS.listed;
            var checkbox = row.querySelector('.kornsw-pmc-select');
            checkbox.checked = false;
            checkbox.disabled = true;
          }
        });
        var message = response.data.addedPmids.length + ' Einträge in die Liste übernommen.';
        if (response.data.failedPmids.length > 0) {
          message += ' Nicht ladbar: ' + response.data.failedPmids.join(', ');
        }
        addStatus.textContent = message;
        updateTabCount('list', response.data.listCount);
        updateTabCount('excluded', response.data.excludedCount);
        updateSelection();
      }).catch(function (error) {
        addStatus.textContent = 'Übernahme fehlgeschlagen: ' + error;
        updateSelection();
      });
    }

    modeRadios.forEach(function (radio) {
      radio.addEventListener('change', function () {
        /* Switching from the builder to the raw query carries the built query over for fine tuning */
        if (radio.value === 'raw' && buildBuilderQuery(builderRows) !== '' && rawInput.dataset.lastMode === 'builder') {
          rawInput.value = buildBuilderQuery(builderRows);
        }
        rawInput.dataset.lastMode = radio.value;
        panels.forEach(function (panel) {
          panel.hidden = panel.getAttribute('data-mode') !== radio.value;
        });
        scheduleSearch();
      });
    });
    rawInput.dataset.lastMode = 'raw';

    idsInput.addEventListener('input', scheduleSearch);
    rawInput.addEventListener('input', scheduleSearch);
    root.querySelector('.kornsw-pmc-builder-add').addEventListener('click', function () {
      addBuilderRow(builderRows, 'tiab', '', scheduleSearch);
    });
    addBuilderRow(builderRows, 'au', '', scheduleSearch);
    addBuilderRow(builderRows, 'cn', '', scheduleSearch);
    builderRows.querySelectorAll('.kornsw-pmc-builder-operator')[1].value = 'NOT';
    builderPreview.textContent = '(leer)';

    root.querySelector('.kornsw-pmc-search-run').addEventListener('click', function () {
      window.clearTimeout(debounceTimer);
      runSearch(0);
    });
    moreButton.addEventListener('click', function () {
      runSearch(nextOffset);
    });
    addButton.addEventListener('click', addSelected);
    selectAll.addEventListener('change', function () {
      resultsBody.querySelectorAll('.kornsw-pmc-select:not(:disabled)').forEach(function (checkbox) {
        checkbox.checked = selectAll.checked;
      });
      updateSelection();
    });

    /* Start with the auto import query: shows what is not in the list yet */
    if (rawInput.value.trim() !== '') {
      runSearch(0);
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.kornsw-pmc-items-form').forEach(initItemsTab);
    var searchRoot = document.querySelector('.kornsw-pmc-search');
    if (searchRoot) {
      initSearchTab(searchRoot);
    }
  });
})();
