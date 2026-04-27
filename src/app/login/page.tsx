'use client';

import { 
  Building2, 
  ChevronRight, 
  Lock, 
  Mail, 
  Github, 
  Chrome,
  Users,
  Briefcase,
  Coins,
  ArrowLeft,
  CheckCircle2,
  ShieldCheck,
  CreditCard,
  User,
  Phone,
  Store,
  ArrowRight
} from 'lucide-react';
import Link from 'next/link';
import { useState, useEffect } from 'react';
import { supabase } from '@/lib/supabase';
import { useRouter } from 'next/navigation';

type AuthMode = 'login' | 'register';
type RegisterRole = 'tenant' | 'landlord' | 'utility' | null;

const MARKETING_SLIDES = [
  {
    image: '/images/real-estate-modern-building-apartment_978119-1782.jpg',
    title: 'Seamless Property Management',
    desc: 'PrimeLink simplifies your real estate operations with state-of-the-art automation and management tools.',
    color: 'from-blue-600/20 to-indigo-600/20'
  },
  {
    image: '/images/xOWyG9KJ1jqmMPFgv1KoscsYpkoQ0lCDD2WTi8WE.jpeg',
    title: 'Secure & Transparent',
    desc: 'From automated payouts to verified identities, we ensure trust and security at every single step.',
    color: 'from-accent-gold/20 to-amber-600/20'
  },
  {
    image: '/images/istockphoto-2203044747-612x612.jpg',
    title: 'Modern Living, Simplified',
    desc: 'Join the most advanced real estate ecosystem designed for landlords, tenants, and utility users.',
    color: 'from-emerald-600/20 to-teal-600/20'
  }
];


export default function LoginPage() {
  const [mode, setMode] = useState<AuthMode>('login');
  const [role, setRole] = useState<RegisterRole>(null);
  const [step, setStep] = useState(1); // 1: Role, 2: Details, 3: Password, 4: ID Display
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [generatedId, setGeneratedId] = useState<string | null>(null);
  const [currentSlide, setCurrentSlide] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const router = useRouter();

  // Form inputs
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fullName, setFullName] = useState('');
  const [phone, setPhone] = useState('');
  const [nationalId, setNationalId] = useState('');

  // Selection States
  const [selectedProperties, setSelectedProperties] = useState<string[]>([]);
  const [selectedUtilities, setSelectedUtilities] = useState<string[]>([]);

  // Carousel Logic
  useEffect(() => {
    const timer = setInterval(() => {
      setCurrentSlide((prev) => (prev + 1) % MARKETING_SLIDES.length);
    }, 5000);
    return () => clearInterval(timer);
  }, []);

  const handleNextStep = (e: React.FormEvent) => {
    e.preventDefault();
    if (role !== null && step === 2) {
      setStep(3);
    } else {
      handleFinalSubmit();
    }
  };

  const handleFinalSubmit = async () => {
    setIsSubmitting(true);
    setError(null);
    
    const { data, error: signUpError } = await supabase.auth.signUp({
      email,
      password,
      options: {
        data: {
          full_name: fullName,
          role: role || 'tenant',
          phone,
          national_id: nationalId
        }
      }
    });

    if (signUpError) {
      setError(signUpError.message);
      setIsSubmitting(false);
      return;
    }

    if (data.user) {
      const suffix = role === 'tenant' ? 'T' : role === 'landlord' ? 'L' : 'U';
      const newId = `PRM-${data.user.id.slice(0, 4)}-${suffix}`;
      setGeneratedId(newId);
      setStep(4);
    }
    
    setIsSubmitting(false);
  };

  const handleLogin = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSubmitting(true);
    setError(null);

    const { error: signInError } = await supabase.auth.signInWithPassword({
      email,
      password,
    });

    if (signInError) {
      setError(signInError.message);
      setIsSubmitting(false);
      return;
    }

    router.push('/');
  };

  const toggleProperty = (prop: string) => {
    setSelectedProperties(prev => 
      prev.includes(prop) ? prev.filter(p => p !== prop) : [...prev, prop]
    );
  };

  const toggleUtility = (util: string) => {
    setSelectedUtilities(prev => 
      prev.includes(util) ? prev.filter(u => u !== util) : [...prev, util]
    );
  };

  const renderLoginForm = () => (
    <div className="space-y-6 animate-in fade-in slide-in-from-right-4 duration-500">
      <div className="text-center space-y-2">
        <h1 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Welcome Back</h1>
        <p className="text-slate-500 dark:text-slate-400 font-medium text-sm">Sign in to manage your property lifestyle</p>
      </div>

      <div className="space-y-4">
        {error && (
          <div className="p-3 bg-red-500/10 border border-red-500/20 rounded-xl text-red-500 text-xs font-bold text-center">
            {error}
          </div>
        )}
        <div className="space-y-2 text-left">
          <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Email Address</label>
          <div className="relative group">
            <Mail className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={18} />
            <input 
              type="email" 
              placeholder="name@company.com" 
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
              className="w-full pl-12 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary placeholder:text-slate-300 dark:placeholder:text-slate-600 shadow-sm"
            />
          </div>
        </div>
        <div className="space-y-2">
          <div className="flex justify-between items-center px-1">
            <label className="text-[10px] font-black uppercase tracking-widest text-slate-400">Password</label>
            <Link href="#" className="text-[10px] font-black uppercase tracking-widest text-accent-gold hover:underline">Forgot?</Link>
          </div>
          <div className="relative group">
            <Lock className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={18} />
            <input 
              type="password" 
              placeholder="••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full pl-12 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary placeholder:text-slate-300"
            />
          </div>
        </div>
      </div>

      <button 
        onClick={handleLogin}
        disabled={isSubmitting}
        className="w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest flex items-center justify-center gap-2 hover:opacity-90 active:scale-[0.98] transition-all shadow-xl disabled:opacity-50"
      >
        {isSubmitting ? 'Signing In...' : 'Sign In'} <ChevronRight size={16} />
      </button>

      <div className="relative">
        <div className="absolute inset-0 flex items-center"><span className="w-full border-t border-slate-100 dark:border-slate-800"></span></div>
        <div className="relative flex justify-center text-[10px] uppercase font-black tracking-widest"><span className="bg-white dark:bg-slate-900 px-3 text-slate-400">Or continue with</span></div>
      </div>

      <div className="grid grid-cols-2 gap-4">
        <button className="flex items-center justify-center gap-2 py-3 border border-slate-100 dark:border-slate-800 rounded-xl font-bold hover:bg-slate-50 dark:hover:bg-slate-950 transition-colors text-xs">
          <Chrome size={16} className="text-red-500" /> Google
        </button>
        <button className="flex items-center justify-center gap-2 py-3 border border-slate-100 dark:border-slate-800 rounded-xl font-bold hover:bg-slate-50 dark:hover:bg-slate-950 transition-colors text-xs">
          <Github size={16} /> GitHub
        </button>
      </div>

      <p className="text-center text-xs font-bold text-slate-500">
        Don&apos;t have an account? <button onClick={() => setMode('register')} className="text-accent-gold hover:underline">Register Now</button>
      </p>
    </div>
  );

  const renderRegisterForm = () => {
    if (step === 4) {
      return (
        <div className="text-center space-y-6 animate-in zoom-in duration-500">
          <div className="w-20 h-20 bg-green-500/10 text-green-500 rounded-full flex items-center justify-center mx-auto mb-4 ring-8 ring-green-500/5">
            <CheckCircle2 size={48} />
          </div>
          <h2 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Success!</h2>
          <p className="text-slate-500 dark:text-slate-400 font-medium text-sm">Welcome to PrimeLink. Please save your unique User ID for secure login.</p>
          
          <div className="p-6 bg-slate-900 rounded-2xl border-2 border-accent-gold shadow-2xl space-y-2 relative overflow-hidden group">
            <div className="absolute inset-0 bg-accent-gold/5 opacity-0 group-hover:opacity-100 transition-opacity"></div>
            <p className="text-[10px] font-black text-accent-gold uppercase tracking-[0.2em] relative z-10">Unique User ID</p>
            <p className="text-3xl font-black text-white tracking-widest font-mono relative z-10">{generatedId}</p>
          </div>

          <button 
            onClick={() => { setMode('login'); setStep(1); setRole(null); }}
            className="w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest hover:opacity-90 active:scale-[0.98] transition-all shadow-lg"
          >
            Continue to Login
          </button>
        </div>
      );
    }

    if (step === 1) {
      return (
        <div className="space-y-8 animate-in fade-in slide-in-from-right-4 duration-500">
          <div className="text-center space-y-2">
            <h2 className="text-3xl font-black text-slate-900 dark:text-white tracking-tight">Create Account</h2>
            <p className="text-slate-500 dark:text-slate-400 font-medium text-sm capitalize">Choose your role to get started</p>
          </div>
          <div className="grid grid-cols-1 gap-4">
            {[
              { id: 'tenant', title: 'Tenant', desc: 'Manage your lease and track utilities.', icon: Users },
              { id: 'landlord', title: 'Landlord', desc: 'Monitor your portfolio and automate payouts.', icon: Briefcase },
              { id: 'utility', title: 'Utility User', desc: 'Secure tokens for electricity & water.', icon: Coins },
            ].map((item) => (
              <div 
                key={item.id}
                onClick={() => { setRole(item.id as RegisterRole); setStep(2); }}
                className="p-5 glass-card-hover cursor-pointer border-2 border-transparent hover:border-accent-gold/30 transition-all flex items-center gap-4 group"
              >
                <div className="w-12 h-12 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center group-hover:bg-slate-900 group-hover:text-white dark:group-hover:bg-white dark:group-hover:text-slate-900 transition-all">
                  <item.icon size={24} />
                </div>
                <div className="flex-1">
                  <h3 className="font-black text-slate-900 dark:text-white text-sm">{item.title}</h3>
                  <p className="text-[10px] text-slate-500 font-medium">{item.desc}</p>
                </div>
                <ArrowRight size={16} className="text-slate-300 group-hover:text-accent-gold transform group-hover:translate-x-1 transition-all" />
              </div>
            ))}
          </div>
          <p className="text-center text-xs font-bold text-slate-500 mt-4">
            Already have an account? <button onClick={() => setMode('login')} className="text-accent-gold hover:underline">Sign In</button>
          </p>
        </div>
      );
    }

    return (
      <form onSubmit={handleNextStep} className="space-y-6 animate-in slide-in-from-right-4 duration-500">
        <div className="flex items-center gap-4 border-b border-slate-100 dark:border-slate-800 pb-4">
          <button 
            type="button" 
            onClick={() => setStep(step - 1)}
            className="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center hover:bg-accent-gold/10 hover:text-accent-gold transition-all"
          >
            <ArrowLeft size={18} />
          </button>
          <div>
             <h2 className="text-lg font-black text-slate-900 dark:text-white capitalize leading-tight">{role} Account</h2>
             <p className="text-[10px] text-slate-500 font-bold uppercase tracking-widest">{step === 3 ? 'Security Setup' : 'Personal Details'}</p>
          </div>
        </div>

        {step === 2 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <FormField label="Full Name" icon={User} placeholder="John Doe" value={fullName} onChange={(val) => setFullName(val)} />
            <FormField label="Email Address" icon={Mail} placeholder="john@example.com" type="email" value={email} onChange={(val) => setEmail(val)} />
            <FormField label="Phone Number" icon={Phone} placeholder="+254 7XX XXX XXX" value={phone} onChange={(val) => setPhone(val)} />
            <FormField label="National ID" icon={ShieldCheck} placeholder="ID-XXXXXXXX" value={nationalId} onChange={(val) => setNationalId(val)} />
            
            {role === 'tenant' && (
              <div className="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-2">
                  <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Select Property</label>
                  <div className="relative group">
                    <Building2 className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300" size={18} />
                    <select className="w-full pl-12 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 appearance-none cursor-pointer font-primary">
                      <option>Property...</option>
                      <option>Elysian Heights</option>
                      <option>Skyline Hub</option>
                    </select>
                  </div>
                </div>
                <div className="space-y-2">
                   <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Unit No.</label>
                   <div className="relative group">
                    <Store className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-300" size={18} />
                    <select className="w-full pl-12 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 appearance-none cursor-pointer font-primary">
                      <option>Unit...</option>
                      <option>A-101</option>
                      <option>B-205</option>
                    </select>
                  </div>
                </div>
              </div>
            )}

            {role === 'landlord' && (
              <div className="sm:col-span-2 space-y-4">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Properties Owned</label>
                <div className="flex flex-wrap gap-2 text-left">
                  {['Elysian', 'Skyline', 'Summit', 'Azure'].map(prop => (
                    <button 
                      key={prop} type="button" onClick={() => toggleProperty(prop)}
                      className={`px-3 py-2 rounded-lg border-2 text-[10px] font-black uppercase tracking-widest transition-all ${
                        selectedProperties.includes(prop) ? 'border-accent-gold bg-accent-gold/5 text-slate-900' : 'border-slate-100 text-slate-300'
                      }`}
                    >
                      {prop}
                    </button>
                  ))}
                </div>
              </div>
            )}

            {role === 'utility' && (
              <div className="sm:col-span-2 space-y-4">
                <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">Preferred Utilities</label>
                <div className="grid grid-cols-2 gap-3">
                  {['Electricity', 'Water'].map(util => (
                    <button 
                      key={util} type="button" onClick={() => toggleUtility(util)}
                      className={`p-3 rounded-xl border-2 text-xs font-bold transition-all flex items-center justify-between ${
                        selectedUtilities.includes(util) ? 'border-accent-gold bg-accent-gold/5 text-slate-900' : 'border-slate-100 text-slate-300'
                      }`}
                    >
                      {util}
                      {selectedUtilities.includes(util) && <CheckCircle2 size={14} className="text-accent-gold" />}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        {step === 3 && (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 animate-in slide-in-from-right-4 duration-300">
            <FormField label="Setup Password" icon={Lock} placeholder="••••••••" type="password" value={password} onChange={(val) => setPassword(val)} />
            <FormField label="Repeat Password" icon={Lock} placeholder="••••••••" type="password" />
          </div>
        )}

        <button 
          disabled={isSubmitting}
          className="w-full py-4 bg-slate-900 dark:bg-slate-50 text-white dark:text-slate-900 rounded-xl font-black text-xs uppercase tracking-widest flex items-center justify-center gap-2 hover:opacity-90 active:scale-[0.98] transition-all shadow-xl"
        >
          {isSubmitting ? <div className="w-4 h-4 border-2 border-current border-t-transparent rounded-full animate-spin"></div> : <>{step === 2 ? 'Continue' : 'Create Account'} <ChevronRight size={16} /></>}
        </button>
      </form>
    );
  }

  return (
    <div className="min-h-screen grid grid-cols-1 lg:grid-cols-12 bg-white dark:bg-slate-950 selection:bg-accent-gold/30">
      {/* Marketing Side (Hidden on Mobile) */}
      <div className="hidden lg:flex lg:col-span-7 xl:col-span-8 relative overflow-hidden bg-slate-900">
        <div className="absolute inset-0 z-0 transition-all duration-1000 ease-in-out">
          {MARKETING_SLIDES.map((slide, idx) => (
            <div 
              key={idx}
              className={`absolute inset-0 transition-opacity duration-1000 ${idx === currentSlide ? 'opacity-100 scale-100' : 'opacity-0 scale-110'}`}
            >
              <img src={slide.image} alt={slide.title} className="w-full h-full object-cover" />
              <div className={`absolute inset-0 bg-gradient-to-br ${slide.color} mix-blend-multiply opacity-60`}></div>
              <div className="absolute inset-0 bg-gradient-to-t from-slate-950 via-slate-900/40 to-transparent"></div>
            </div>
          ))}
        </div>

        <div className="relative z-10 w-full h-full flex flex-col justify-between p-16">
          <div className="flex items-center gap-3">
             <div className="p-3 bg-white/10 backdrop-blur-xl rounded-2xl border border-white/20 text-white">
                <Building2 size={32} />
             </div>
             <span className="text-3xl font-black text-white tracking-widest uppercase">PrimeLink</span>
          </div>

          <div className="max-w-xl space-y-6">
             <div className="flex gap-2">
                {MARKETING_SLIDES.map((_, idx) => (
                  <div key={idx} className={`h-1 rounded-full transition-all duration-500 ${idx === currentSlide ? 'w-12 bg-accent-gold' : 'w-4 bg-white/30'}`}></div>
                ))}
             </div>
             <h2 className="text-6xl font-black text-white leading-tight animate-in slide-in-from-bottom-8 duration-700">
               {MARKETING_SLIDES[currentSlide].title}
             </h2>
             <p className="text-xl text-slate-300 font-medium leading-relaxed max-w-lg">
               {MARKETING_SLIDES[currentSlide].desc}
             </p>
          </div>

          <div className="flex items-center gap-8 text-[10px] font-black text-white/50 uppercase tracking-[0.4em]">
             <span>Real Estate</span>
             <span>Utilities</span>
             <span>Trust</span>
          </div>
        </div>
      </div>

      {/* Auth Form Side */}
      <div className="col-span-1 lg:col-span-5 xl:col-span-4 flex items-center justify-center p-8 sm:p-12 relative">
        {/* Background blobs for aesthetics */}
        <div className="absolute top-1/4 right-1/4 w-64 h-64 bg-accent-gold/10 rounded-full blur-[100px] -z-10"></div>
        <div className="absolute bottom-1/4 left-1/4 w-64 h-64 bg-blue-500/10 rounded-full blur-[100px] -z-10"></div>
        
        <div className="w-full max-w-sm space-y-8">
           <div className="lg:hidden text-center mb-12">
              <div className="inline-flex items-center justify-center p-3 rounded-2xl bg-slate-900 text-white shadow-xl mb-4">
                <Building2 size={24} />
              </div>
              <h1 className="text-2xl font-black text-slate-900 tracking-tight uppercase">PrimeLink</h1>
           </div>

           {mode === 'login' ? renderLoginForm() : renderRegisterForm()}
        </div>
      </div>
    </div>
  );
}

function FormField({ label, icon: Icon, placeholder, type = "text", value, onChange }: { label: string, icon: any, placeholder: string, type?: string, value?: string, onChange?: (val: string) => void }) {
  return (
    <div className="space-y-2 text-left">
      <label className="text-[10px] font-black text-slate-400 uppercase tracking-widest">{label}</label>
      <div className="relative group">
        <Icon className="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-accent-gold transition-colors" size={18} />
        <input 
          type={type} 
          placeholder={placeholder}
          value={value}
          onChange={(e) => onChange?.(e.target.value)}
          required
          className="w-full pl-12 pr-4 py-3 bg-slate-50 dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl text-sm font-bold focus:outline-hidden focus:ring-2 focus:ring-accent-gold/20 transition-all font-primary placeholder:text-slate-300 dark:placeholder:text-slate-600 shadow-sm"
        />
      </div>
    </div>
  );
}
