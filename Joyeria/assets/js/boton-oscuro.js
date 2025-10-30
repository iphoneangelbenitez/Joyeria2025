/**
 * Función para cambiar entre tema claro y oscuro.
 * @param {Event} event - El evento de clic
 */
function toggleTheme(event) {
  
  // 1. ¡NUEVO! Detiene el evento.
  // Esto evita que el clic "se propague" a otros elementos (como el menú)
  // o que el botón intente hacer su acción por defecto (si fuera un link <a>).
  if (event) {
    event.preventDefault(); 
    event.stopPropagation(); // <-- Esta es la clave para tu problema
  }

  // 2. Busca el tag <html> y activa/desactiva la clase 'theme-light'
  const isLight = document.documentElement.classList.toggle('theme-light');

  // 3. Guarda la preferencia en el navegador
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
  
  // 4. Busca el botón que tiene el ID 'theme-toggle-btn'
  const themeButton = document.getElementById('theme-toggle-btn');
  
  if (themeButton) {
    // 5. Si lo encuentra, le asigna la función 'toggleTheme' al hacer clic
    // (Ahora la función 'toggleTheme' recibirá el 'event')
    themeButton.addEventListener('click', toggleTheme);
  } else {
    // Aviso por si no encuentra el botón
    console.warn('No se encontró el botón con id "theme-toggle-btn"');
  }
});