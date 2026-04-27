(function () {
  const API_BASE = 'http://localhost/aharam/backend-api';
  fetch(API_BASE + '/settings/public')
    .then(r => r.json())
    .then(res => {
      const name = res?.data?.platform_name;
      if (!name) return;
      window._platformName = name;
      document.querySelectorAll('[data-pname]').forEach(el => {
        el.textContent = name;
      });
      document.title = document.title.replace(/Aharam/g, name);
    })
    .catch(() => {});
})();
