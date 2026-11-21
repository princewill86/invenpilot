export type Platform = {
  name: string; headline: string; description: string; support_email: string | null;
  seo_title: string; seo_description: string; search_indexing: boolean;
  registration_enabled: boolean; password_reset_enabled: boolean; password_minimum: number;
  default_currency: string; currencies: string[]; terms: string; privacy: string; legal_version: string;
  logo_url: string | null; favicon_url: string | null;
}
