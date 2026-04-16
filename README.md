# 🚀 WooCommerce Coupon Affiliation System

System do zarządzania siecią ambasadorów oparty na kodach rabatowych WooCommerce. Stworzony przy wsparciu AI (Cursor).

## 📝 O projekcie
Wtyczka umożliwia przypisywanie konkretnych kodów rabatowych do użytkowników z rolą **Ambasador**. Automatycznie oblicza prowizję od sprzedaży netto i udostępnia dedykowany panel dla partnerów oraz administratora.

## ✨ Główne Funkcje
- **Rola Ambasador:** Nowy typ użytkownika w systemie WordPress.
- **Powiązanie Kuponów:** Możliwość przypisania Ambasadora do kodu rabatowego w edycji kuponu.
- **Matematyka Netto:** Prowizja liczona od: `(Suma zamówienia - Rabaty) - (Podatki + Koszty wysyłki)`.
- **Panel Ambasadora:** Zakładka w "Moje konto" (WooCommerce) z historią zarobków.
- **System Payouts:** Panel Admina do oznaczania wypłaconych prowizji.
- **Automatyzacja Statusów:** Automatyczne zerowanie prowizji przy anulowaniu lub zwrocie zamówienia.
- **Powiadomienia:** E-mail do ambasadora po sfinalizowaniu zamówienia.

## 🛠 Instalacja i Konfiguracja
1. **Wgraj wtyczkę** jako plik ZIP na serwer.
2. **Aktywuj wtyczkę.**
3. **KLUCZOWY KROK:** Wejdź w `Ustawienia -> Bezpośrednie odnośniki` i kliknij **Zapisz zmiany**, aby aktywować adres URL panelu `/ambassador-stats/`.
4. **Stwórz Ambasadora:** Dodaj użytkownika i przypisz mu rolę `Ambassador`.
5. **Ustaw stawkę:** W profilu użytkownika wpisz `%` prowizji (domyślnie 10%).

## 🔍 Informacje Techniczne (Dla AI / Dewelopera)
Jeśli będziesz rozwijać wtyczkę, to są kluczowe metadane (Meta Keys):

### Użytkownicy:
- `_ambassador_commission_rate` - Indywidualna stawka % prowizji.

### Kupony:
- `_assigned_ambassador_id` - ID użytkownika (ambasadora) przypisanego do kodu.

### Zamówienia:
- `_order_ambassador_id` - ID ambasadora, który zarobił na tym zamówieniu.
- `_order_ambassador_commission` - Kwota prowizji w walucie sklepu.
- `_commission_payout_status` - Status wypłaty (`unpaid`, `paid`, `void`).

## ⚡ Rozwój lokalny (Development)
Projekt używa `@wordpress/env`.
- Start: `npm start`
- Stop: `npm stop`
- Domyślne dane: `admin` / `password`