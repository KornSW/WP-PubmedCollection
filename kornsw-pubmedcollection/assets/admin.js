/* Admin page "Beiträge > PubMed (KornSW)": keyword highlighting and exclude checkboxes of the hit list. */
(function () {
  'use strict';

  var STORAGE_KEY = 'kornsw-pmc-highlight';

  function escapeRegExp(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function buildKeywordPattern(input) {
    var keywords = input.split(/[\s,;]+/).filter(function (keyword) {
      return keyword.length > 0;
    });
    if (keywords.length === 0) {
      return null;
    }
    return new RegExp('(' + keywords.map(escapeRegExp).join('|') + ')', 'gi');
  }

  /* Rebuilds the element from its original text, wrapping matches in <mark> (text nodes only, no innerHTML). */
  function highlightElement(element, pattern) {
    var text = element.getAttribute('data-original');
    element.textContent = '';
    if (pattern === null) {
      element.textContent = text;
      return false;
    }
    var found = false;
    var lastIndex = 0;
    var match;
    pattern.lastIndex = 0;
    while ((match = pattern.exec(text)) !== null) {
      if (match[0].length === 0) {
        pattern.lastIndex++;
        continue;
      }
      found = true;
      element.appendChild(document.createTextNode(text.substring(lastIndex, match.index)));
      var mark = document.createElement('mark');
      mark.textContent = match[0];
      element.appendChild(mark);
      lastIndex = match.index + match[0].length;
    }
    element.appendChild(document.createTextNode(text.substring(lastIndex)));
    return found;
  }

  function initHighlight(table) {
    var input = document.getElementById('kornsw-pmc-highlight');
    var counter = document.getElementById('kornsw-pmc-highlight-count');
    if (!input) {
      return;
    }
    var elements = table.querySelectorAll('.kornsw-pmc-hl');
    elements.forEach(function (element) {
      element.setAttribute('data-original', element.textContent);
    });

    function apply() {
      var pattern = buildKeywordPattern(input.value);
      var matchingRows = 0;
      table.querySelectorAll('tbody tr').forEach(function (row) {
        var rowMatches = false;
        row.querySelectorAll('.kornsw-pmc-hl').forEach(function (element) {
          if (highlightElement(element, pattern)) {
            rowMatches = true;
          }
        });
        if (rowMatches) {
          matchingRows++;
        }
      });
      counter.textContent = pattern === null ? '' : matchingRows + ' Einträge mit Treffer';
      try {
        window.localStorage.setItem(STORAGE_KEY, input.value);
      } catch (error) {
        /* storage is only a convenience */
      }
    }

    try {
      input.value = window.localStorage.getItem(STORAGE_KEY) || '';
    } catch (error) {
      input.value = '';
    }
    input.addEventListener('input', apply);
    apply();
  }

  function initExclusions(table, form) {
    var checkboxes = table.querySelectorAll('.kornsw-pmc-exclude');
    var counter = document.getElementById('kornsw-pmc-excluded-count');

    function updateState() {
      var excludedCount = 0;
      checkboxes.forEach(function (checkbox) {
        checkbox.closest('tr').classList.toggle('is-excluded', checkbox.checked);
        if (checkbox.checked) {
          excludedCount++;
        }
      });
      if (counter) {
        counter.textContent = String(excludedCount);
      }
    }

    checkboxes.forEach(function (checkbox) {
      checkbox.addEventListener('change', updateState);
    });

    /* All checkbox states travel as one JSON field (avoids max_input_vars limits). */
    form.addEventListener('submit', function () {
      var displayed = [];
      var excluded = [];
      checkboxes.forEach(function (checkbox) {
        displayed.push(checkbox.value);
        if (checkbox.checked) {
          excluded.push(checkbox.value);
        }
      });
      form.elements.exclusions_json.value = JSON.stringify({ displayed: displayed, excluded: excluded });
      form.elements.exclusions_submitted.value = '1';
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('kornsw-pmc-collection-form');
    var table = document.querySelector('.kornsw-pmc-hits');
    if (!form || !table) {
      return;
    }
    initExclusions(table, form);
    initHighlight(table);
  });
})();
