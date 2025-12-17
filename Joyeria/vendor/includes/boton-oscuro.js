/**
 * Función para cambiar entre tema claro y oscuro.
 */
function toggleTheme() {
  
  // 1. Busca el tag <html> y activa/desactiva la clase 'theme-light'
  // Usamos documentElement (el <html>) porque es lo que lee
  // el script anti-parpadeo que pondremos en el <head>.
  const isLight = document.documentElement.classList.toggle('theme-light');

  // 2. Guarda la preferencia en el navegador
  if (isLight) {
    localStorage.setItem('theme', 'light');
    console.log('Cambiado a Tema Claro');
  } else {
    localStorage.setItem('theme', 'dark');
    console.log('Cambiado a Tema Oscuro');
  }
}

/**
 * Esta función espera a que la página cargue por completo
 * antes de buscar el botón y asignarle la función 'toggleTheme'.
 */
document.addEventListener('DOMContentLoaded', () => {
  
  // 3. Busca el botón que tiene el ID 'theme-toggle-btn'
  const themeButton = document.getElementById('theme-toggle-btn');
  
  if (themeButton) {
    // 4. Si lo encuentra, le asigna la función 'toggleTheme' al hacer clic
    themeButton.addEventListener('click', toggleTheme);
  } else {
    // Aviso por si no encuentra el botón
    console.warn('No se encontró el botón con id "theme-toggle-btn"');
  }
});