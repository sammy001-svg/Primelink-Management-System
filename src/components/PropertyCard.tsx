import Link from 'next/link';
import { Property } from '@/lib/mock-data';
import { Home, MapPin, Maximize, BedDouble, Bath } from 'lucide-react';

interface PropertyCardProps {
  property: Property;
}

export default function PropertyCard({ property }: PropertyCardProps) {
  const statusColors = {
    Available: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    Rented: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
    Maintenance: 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
    Sold: 'bg-slate-100 text-slate-700 dark:bg-slate-900/30 dark:text-slate-400',
  };

  return (
    <div className="glass-card overflow-hidden group hover:shadow-lg transition-all duration-300">
      <div className="relative h-48 overflow-hidden">
        <img 
          src={property.images[0]} 
          alt={property.title}
          className="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110"
        />
        <div className={`absolute top-4 right-4 px-3 py-1 rounded-full text-xs font-semibold ${statusColors[property.status]}`}>
          {property.status}
        </div>
        <div className="absolute bottom-4 left-4 px-3 py-1 rounded-lg bg-slate-900/60 backdrop-blur-sm text-white text-sm font-bold">
          KSh {property.price.toLocaleString()}{property.listingType === 'Sale' ? '' : '/mo'}
        </div>
      </div>
      
      <div className="p-5 space-y-4">
        <div>
          <div className="flex items-center gap-1 text-xs text-accent-gold font-semibold uppercase tracking-wider mb-1">
            <Home size={12} />
            {property.type}
          </div>
          <h3 className="text-xl font-bold text-slate-900 dark:text-white line-clamp-1">{property.title}</h3>
          <div className="flex items-center gap-1 text-slate-500 text-sm mt-1">
            <MapPin size={14} />
            {property.location}
          </div>
        </div>

        <div className="flex items-center justify-between py-3 border-y border-slate-100 dark:border-slate-800">
          {property.bedrooms && (
            <div className="flex items-center gap-1 text-slate-600 dark:text-slate-400 text-sm">
              <BedDouble size={16} />
              <span>{property.bedrooms} Bed</span>
            </div>
          )}
          {property.bathrooms && (
            <div className="flex items-center gap-1 text-slate-600 dark:text-slate-400 text-sm">
              <Bath size={16} />
              <span>{property.bathrooms} Bath</span>
            </div>
          )}
          <div className="flex items-center gap-1 text-slate-600 dark:text-slate-400 text-sm">
            <Maximize size={16} />
            <span>{property.area} sqft</span>
          </div>
        </div>

        <Link 
          href={`/properties/${property.id}`}
          className="block w-full text-center py-2.5 bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-white rounded-lg font-medium hover:bg-slate-900 hover:text-white dark:hover:bg-slate-50 dark:hover:text-slate-900 transition-all duration-300"
        >
          View Details
        </Link>
      </div>
    </div>
  );
}
