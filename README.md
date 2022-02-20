# wp-zapsters

This [WordPress Plugin](https://developer.wordpress.org/plugins/) relays HTTP POST
requests from a DeroZap box to one or two configured endpoints. It also maintains
one year of request history for debugging.

This plugin was developed during a migration from using Active4.me to a custom
Google Apps Script webapp. The DeroZap box does not accept URLs with dashes, and
GAS webapp deployment URLs always have dashes. Using this relay has the additional
benefit of being able to change from/to Active4.me or different GAS webapps without
climbing up a ladder to change the DeroZap box's configured URL.

If the DeroZap box's URL does need to be changed, consult [this documentation](changing-station-url.pdf).
