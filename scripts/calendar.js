function nextMonth(currentMonth, currentYear) {
  let nextMonth = currentMonth + 1;
  let nextYear = currentYear;
  if (nextMonth > 12) {
    nextMonth = 1;
    nextYear += 1;
  }
  window.location.href = `calendar.php?month=${nextMonth}&year=${nextYear}`;
}

function prevMonth(currentMonth, currentYear) {
  let prevMonth = currentMonth - 1;
  let prevYear = currentYear;
  if (prevMonth < 1) {
    prevMonth = 12;
    prevYear -= 1;
  }
  window.location.href = `calendar.php?month=${prevMonth}&year=${prevYear}`;
}

function currentMoth() {
    window.location.href = `calendar.php`;
}

function showExportPopup() {
  document.getElementById('subscriptions_calendar').classList.add('is-open');
}

// The feed URL carries an API key with only the "ical" scope. Keys are stored
// hashed, so a link can be shown only when it is created; older links keep
// working, and Profile -> API keys lists and revokes them.
function createCalendarLink(button) {
  button.disabled = true;
  fetch('endpoints/user/createapikey.php', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': window.csrfToken,
    },
    body: JSON.stringify({ name: button.dataset.name, scopes: ['ical'] })
  })
    .then(response => response.json())
    .then(data => {
      button.disabled = false;
      if (!data.success) {
        showErrorMessage(data.message);
        return;
      }
      const feedUrl = new URL('api/subscriptions/get_ical_feed.php', window.location.href);
      feedUrl.search = '';
      feedUrl.searchParams.set('api_key', data.apiKey);
      document.getElementById('iCalendarUrl').value = feedUrl.toString();
      button.classList.add('hide');
      document.getElementById('copyCalendarLink').classList.remove('hide');
    })
    .catch(() => {
      button.disabled = false;
      showErrorMessage(translate('unknown_error'));
    });
}

function closePopup() {
  document.getElementById('subscriptions_calendar').classList.remove('is-open');
}

function copyToClipboard() {
  const urlField = document.getElementById('iCalendarUrl');
  urlField.select();
  urlField.setSelectionRange(0, 99999); // For mobile devices
  navigator.clipboard.writeText(urlField.value)
      .then(() => {
          showSuccessMessage(translate('copied_to_clipboard'));
      })
      .catch(() => {
          showErrorMessage(translate('unknown_error'));
      });
}