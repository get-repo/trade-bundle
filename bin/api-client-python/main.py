from btcmarkets import BTCMarkets 
import sys
args = sys.argv
api_key = args[1]
private_key = args[2]
method = args[3]
del args[0],args[0],args[0],args[0]
client = BTCMarkets (api_key, private_key)
print getattr(client, method)(*args)
