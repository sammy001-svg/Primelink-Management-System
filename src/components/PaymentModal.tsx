'use client';

import { 
  Plus, Wallet, Droplets, Zap, 
  ChevronRight, Apple, CreditCard,
  CheckCircle2, AlertCircle
} from 'lucide-react';
import { useState } from 'react';

interface PaymentModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: (paymentDetails: { amount: number, method: string, type: string }) => void;
  balance?: number;
}

export default function PaymentModal({ isOpen, onClose, onSuccess, balance = 0 }: PaymentModalProps) {
  const [paymentStep, setPaymentStep] = useState(1);
  const [selectedBill, setSelectedBill] = useState<'Rent' | 'Water' | 'Both' | null>(null);
  const [paymentMethod, setPaymentMethod] = useState<string | null>(null);
  const [isProcessing, setIsProcessing] = useState(false);

  if (!isOpen) return null;

  const handlePayment = () => {
    setIsProcessing(true);
    setTimeout(() => {
      const amount = selectedBill === 'Both' ? 5700 : selectedBill === 'Rent' ? 4500 : 1200;
      onSuccess({
        amount,
        method: paymentMethod || 'Standard',
        type: selectedBill || 'Bill'
      });
      setIsProcessing(false);
      resetAndClose();
    }, 2000);
  };

  const resetAndClose = () => {
    setPaymentStep(1);
    setSelectedBill(null);
    setPaymentMethod(null);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md animate-in fade-in duration-300">
      <div className="glass-card w-full max-w-lg p-8 relative overflow-hidden border-t-4 border-t-accent-gold shadow-2xl">
        <button 
          onClick={resetAndClose}
          className="absolute top-4 right-4 p-2 text-slate-400 hover:text-white transition-colors"
        >
          <Plus className="rotate-45" size={24} />
        </button>

        {paymentStep === 1 && (
          <div className="space-y-6 animate-in slide-in-from-bottom-4 duration-500">
            <div className="text-center">
              <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight">Select Bill</h2>
              <p className="text-slate-500 text-sm mt-1">What would you like to pay for?</p>
            </div>
            
            <div className="grid grid-cols-1 gap-4">
              {[
                { id: 'Rent', icon: Wallet, desc: 'Monthly Residential Rent', amount: 'KSh 4,500' },
                { id: 'Water', icon: Droplets, desc: 'March Water Usage', amount: 'KSh 1,200', color: 'text-blue-500' },
                { id: 'Both', icon: Zap, desc: 'Pay All Outstanding Bills', amount: 'KSh 5,700', color: 'text-accent-gold' }
              ].map((item) => (
                <button
                  key={item.id}
                  onClick={() => { setSelectedBill(item.id as any); setPaymentStep(2); }}
                  className="flex items-center justify-between p-5 bg-slate-50/50 dark:bg-slate-900/50 border border-slate-100 dark:border-slate-800 rounded-2xl hover:border-accent-gold transition-all group active:scale-95"
                >
                  <div className="flex items-center gap-4">
                    <div className={`p-3 bg-white dark:bg-slate-800 rounded-xl shadow-sm ${item.color || 'text-slate-900 dark:text-white'}`}>
                      <item.icon size={24} />
                    </div>
                    <div className="text-left">
                      <p className="font-black text-slate-900 dark:text-white">{item.id}</p>
                      <p className="text-[10px] font-black text-slate-400 uppercase tracking-widest">{item.desc}</p>
                    </div>
                  </div>
                  <p className="font-black text-slate-900 dark:text-white text-sm">{item.amount}</p>
                </button>
              ))}
            </div>
          </div>
        )}

        {paymentStep === 2 && (
          <div className="space-y-6 animate-in slide-in-from-bottom-4 duration-500">
            <div className="text-center">
               <button onClick={() => setPaymentStep(1)} className="text-[10px] font-black text-accent-gold uppercase tracking-widest mb-2 hover:underline">← Go Back</button>
               <h2 className="text-2xl font-black text-slate-900 dark:text-white tracking-tight">Select Method</h2>
               <p className="text-slate-500 text-sm mt-1">Paying for <span className="font-bold text-slate-900 dark:text-white">{selectedBill}</span></p>
            </div>

            <div className="grid grid-cols-2 gap-4">
              {[
                { id: 'M-Pesa', logo: 'M' },
                { id: 'Airtel Money', logo: 'A' },
                { id: 'Bank Transfer', logo: 'B' },
                { id: 'Manual', logo: 'P' }
              ].map((method) => (
                <button
                  key={method.id}
                  onClick={() => setPaymentMethod(method.id)}
                  className={`p-6 rounded-2xl border-2 transition-all flex flex-col items-center gap-2 group ${
                    paymentMethod === method.id 
                      ? 'border-accent-gold bg-accent-gold/5' 
                      : 'border-slate-100 dark:border-slate-800 hover:border-slate-300 dark:hover:border-slate-600'
                  }`}
                >
                  <div className={`w-12 h-12 flex items-center justify-center rounded-xl font-black text-xl shadow-inner ${
                    paymentMethod === method.id ? 'bg-accent-gold text-slate-900' : 'bg-slate-100 dark:bg-slate-800 text-slate-400'
                  }`}>
                    {method.logo}
                  </div>
                  <p className="font-black text-[10px] uppercase tracking-widest text-slate-900 dark:text-white">{method.id}</p>
                </button>
              ))}
            </div>

            <button 
              onClick={handlePayment}
              disabled={!paymentMethod || isProcessing}
              className="w-full py-4 bg-slate-900 dark:bg-accent-gold text-white dark:text-slate-900 rounded-2xl font-black text-sm uppercase tracking-[0.2em] shadow-xl hover:translate-y-[-2px] active:scale-95 transition-all disabled:opacity-50 disabled:translate-y-0"
            >
              {isProcessing ? 'Processing...' : 'Complete Payment'}
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
