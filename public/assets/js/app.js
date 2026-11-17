document.querySelector('.password-toggle')?.addEventListener('click', function () {
  const input = document.getElementById('password');
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  this.textContent = show ? 'Hide' : 'Show';
  this.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  this.setAttribute('aria-pressed', String(show));
});
document.querySelector('.login-card form')?.addEventListener('submit', function () {
  const button = this.querySelector('[type="submit"]');
  button.disabled = true;
  button.textContent = 'Signing in…';
});
