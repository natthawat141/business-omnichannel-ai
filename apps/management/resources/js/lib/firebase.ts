import { initializeApp, getApps, getApp, type FirebaseApp } from 'firebase/app';
import { getAuth, GoogleAuthProvider, type Auth } from 'firebase/auth';
import { getAnalytics, isSupported, type Analytics } from 'firebase/analytics';

export const firebaseConfig = {
    apiKey: (import.meta.env.VITE_FIREBASE_API_KEY as string | undefined) || 'AIzaSyD8ppBlN9NzC0MsN9n6XEC27hdkBjTstBA',
    authDomain: (import.meta.env.VITE_FIREBASE_AUTH_DOMAIN as string | undefined) || 'monica-88d72.firebaseapp.com',
    projectId: (import.meta.env.VITE_FIREBASE_PROJECT_ID as string | undefined) || 'monica-88d72',
    storageBucket: (import.meta.env.VITE_FIREBASE_STORAGE_BUCKET as string | undefined) || 'monica-88d72.firebasestorage.app',
    messagingSenderId: (import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID as string | undefined) || '315771745530',
    appId: (import.meta.env.VITE_FIREBASE_APP_ID as string | undefined) || '1:315771745530:web:a84ad38f54ec662948c543',
    measurementId: (import.meta.env.VITE_FIREBASE_MEASUREMENT_ID as string | undefined) || 'G-YB8N1288ZZ',
};

export const app: FirebaseApp = getApps().length === 0 ? initializeApp(firebaseConfig) : getApp();
export const auth: Auth = getAuth(app);
export const googleProvider: GoogleAuthProvider = new GoogleAuthProvider();

let analytics: Analytics | null = null;
if (typeof window !== 'undefined') {
    isSupported().then((supported) => {
        if (supported) {
            analytics = getAnalytics(app);
        }
    });
}

export { analytics };
