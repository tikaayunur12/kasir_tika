// File: auth.js
class AuthSystem {
    static checkAuth(requiredRole = null) {
        const currentUser = localStorage.getItem('currentUser');
        const isLoggedIn = localStorage.getItem('isLoggedIn');
        
        if (!currentUser || !isLoggedIn) {
            window.location.href = 'login.html';
            return false;
        }
        
        const userData = JSON.parse(currentUser);
        
        if (requiredRole && userData.role !== requiredRole) {
            alert('Anda tidak memiliki akses ke halaman ini!');
            window.location.href = 'index.html';
            return false;
        }
        
        return userData;
    }
    
    static getCurrentUser() {
        const currentUser = localStorage.getItem('currentUser');
        return currentUser ? JSON.parse(currentUser) : null;
    }
    
    static logout() {
        localStorage.removeItem('currentUser');
        localStorage.removeItem('isLoggedIn');
        window.location.href = 'login.html';
    }
}