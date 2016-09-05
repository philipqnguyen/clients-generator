// ===================================================================================================
//						   _  __	 _ _
//						  | |/ /__ _| | |_ _  _ _ _ __ _
//						  | ' </ _` | |  _| || | '_/ _` |
//						  |_|\_\__,_|_|\__|\_,_|_| \__,_|
//
// This file is part of the Kaltura Collaborative Media Suite which allows users
// to do with audio, video, and animation what Wiki platfroms allow them to do with
// text.
//
// Copyright (C) 2006-2011  Kaltura Inc.
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as
// published by the Free Software Foundation, either version 3 of the
// License, or (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.
//
// @ignore
// ===================================================================================================
package com.kaltura.client;

import com.kaltura.client.enums.KalturaSessionType;
import com.kaltura.client.utils.EncryptionUtils;
import com.kaltura.client.utils.ParseUtils;
import org.apache.commons.codec.binary.Base64;

import java.io.Serializable;
import java.net.URLEncoder;
import java.security.GeneralSecurityException;
import java.security.NoSuchAlgorithmException;
import java.util.Random;

/**
 * Contains non-generated client logic. Includes the doQueue method which is responsible for
 * making HTTP calls to the Kaltura server.
 * 
 * @author jpotts
 *
 */
@SuppressWarnings("serial")
public class KalturaClientBase implements Serializable {

	private static final String UTF8_CHARSET = "UTF-8";

    // KS v2 constants
    private static final int BLOCK_SIZE = 16;
    private static final String FIELD_EXPIRY = "_e";
    private static final String FIELD_USER = "_u";
	private static final String FIELD_TYPE = "_t";
	private static final int RANDOM_SIZE = 16; 

	private static final int MAX_DEBUG_RESPONSE_STRING_LENGTH = 1024;
	public static final int EXPIRY = 86400;

	protected ConnectionConfiguration connectionConfiguration;

	/** will be added to the request body to configure version and etc **/
	protected KalturaParams clientConfiguration = new KalturaParams(); // generated by php script from input file
	/** will be added to the request body and will include session related data (ks, partnerId etc) */
	protected KalturaParams requestConfiguration = new KalturaParams(); // generated by php script from input file

	private static IKalturaLogger logger = KalturaLogger.getLogger(KalturaClientBase.class);

	public KalturaClientBase() {
	}

	public KalturaClientBase(ConnectionConfiguration config) {
		// sets the default configuration values and add/overwrite with new properties from config
		this.connectionConfiguration = new ConnectionConfiguration(config);
	}

	public void setClientConfigProperty(String key, String value){
		this.clientConfiguration.put(key, value);
	}

	/**
	 * @return String
	 */
	public String getClientConfigProperty(String key){
		if(this.clientConfiguration.containsKey(key)){
			return (String) this.clientConfiguration.get(key);
		}

		return null;
	}

	public KalturaParams getClientConfiguration() {
		return clientConfiguration;
	}

	public void setRequestConfigProperty(String key, String value){
		this.clientConfiguration.put(key, value);
	}

	/**
	 * @return String
	 */
	public String getRequestConfigProperty(String key){
		if(this.clientConfiguration.containsKey(key)){
			return (String) this.clientConfiguration.get(key);
		}

		return null;
	}

	public KalturaParams getRequestConfiguration() {
		return requestConfiguration;
	}

	public void setConnectionConfiguration(ConnectionConfiguration connectionConfiguration) {
		this.connectionConfiguration = connectionConfiguration;
	}

	public ConnectionConfiguration getConnectionConfiguration() {
		return this.connectionConfiguration;
	}


//-----------------------------------------------------------------------------------------------------------------------------------------------

	public String generateSession(String adminSecretForSigning, String userId, KalturaSessionType type, int partnerId) throws Exception
	{
		return this.generateSession(adminSecretForSigning, userId, type, partnerId, EXPIRY);
	}
	
	public String generateSession(String adminSecretForSigning, String userId, KalturaSessionType type, int partnerId, int expiry) throws Exception
	{
		return this.generateSession(adminSecretForSigning, userId, type, partnerId, expiry, "");
	}

	/** generate "ks" to be used fro requests - OVP */
	public String generateSession(String adminSecretForSigning, String userId, KalturaSessionType type, int partnerId, int expiry, String privileges) throws Exception
	{
		try
		{
			// initialize required values
			int rand = (int)(Math.random() * 32000);
			expiry += (int)(System.currentTimeMillis() / 1000);
			
			// build info string
			StringBuilder sbInfo = new StringBuilder();
			sbInfo.append(partnerId).append(";"); // index 0 - partner ID
			sbInfo.append(partnerId).append(";"); // index 1 - partner pattern - using partner ID
			sbInfo.append(expiry).append(";"); // index 2 - expiration timestamp
			sbInfo.append(type.getValue()).append(";"); // index 3 - session type
			sbInfo.append(rand).append(";"); // index 4 - random number
			sbInfo.append(userId).append(";"); // index 5 - user ID
			sbInfo.append(privileges); // index 6 - privileges
			
			byte[] infoSignature = EncryptionUtils.encryptSHA1(adminSecretForSigning + (sbInfo.toString()));
			
			// convert signature to hex:
			String signature = ParseUtils.convertToHex(infoSignature);
			
			// build final string to base64 encode
			StringBuilder sbToEncode = new StringBuilder();
			sbToEncode.append(signature.toString()).append("|").append(sbInfo.toString());
			
			// encode the signature and info with base64
			String hashedString = new String(Base64.encodeBase64(sbToEncode.toString().getBytes()));
			
			// remove line breaks in the session string
			String ks = hashedString.replace("\n", "");
			ks = hashedString.replace("\r", "");
			
			// return the generated session key (KS)
			return ks;
		} catch (NoSuchAlgorithmException ex)
		{
			throw new Exception(ex);
		}
	}

	public String generateSessionV2(String adminSecretForSigning, String userId, KalturaSessionType type, int partnerId, int expiry, String privileges) throws Exception
	{
		try {
		// build fields array
		KalturaParams fields = new KalturaParams();
		String[] privilegesArr = privileges.split(",");
		for (String curPriv : privilegesArr) {
			String privilege = curPriv.trim();
			if(privilege.length() == 0)
				continue;
			if(privilege.equals("*"))
				privilege = "all:*";
			
			String[] splittedPriv = privilege.split(":");
			if(splittedPriv.length>1) {
				fields.add(splittedPriv[0], URLEncoder.encode(splittedPriv[1], UTF8_CHARSET));
			} else {
				fields.add(splittedPriv[0], "");
			}
		}
		
		Integer expiryInt = (int)(System.currentTimeMillis() / 1000) + expiry;
		String expStr = expiryInt.toString();
		fields.add(FIELD_EXPIRY,  expStr);
		fields.add(FIELD_TYPE, Integer.toString(type.getValue()));
		fields.add(FIELD_USER, userId);
		
		// build fields string
		byte[] randomBytes = createRandomByteArray(RANDOM_SIZE);
		byte[] fieldsByteArray = fields.toQueryString().getBytes();
		int totalLength = randomBytes.length + fieldsByteArray.length;
		byte[] fieldsAndRandomBytes = new byte[totalLength];
		System.arraycopy(randomBytes, 0, fieldsAndRandomBytes, 0, randomBytes.length);
		System.arraycopy(fieldsByteArray, 0, fieldsAndRandomBytes, randomBytes.length, fieldsByteArray.length);

		byte[] infoSignature = EncryptionUtils.encryptSHA1(fieldsAndRandomBytes);
		byte[] input = new byte[infoSignature.length + fieldsAndRandomBytes.length];
		System.arraycopy(infoSignature, 0, input, 0, infoSignature.length);
		System.arraycopy(fieldsAndRandomBytes,0,input,infoSignature.length, fieldsAndRandomBytes.length);
		
		// encrypt and encode
		byte[] encryptedFields = EncryptionUtils.encryptAES(adminSecretForSigning, input);
		String prefix = "v2|" + partnerId + "|";
		
		byte[] output = new byte[encryptedFields.length + prefix.length()];
		System.arraycopy(prefix.getBytes(), 0, output, 0, prefix.length());
		System.arraycopy(encryptedFields,0,output,prefix.length(), encryptedFields.length);
		
		String encodedKs = new String(Base64.encodeBase64(output));
		encodedKs = encodedKs.replaceAll("\\+", "-");
		encodedKs = encodedKs.replaceAll("/", "_");
		encodedKs = encodedKs.replace("\n", "");
		encodedKs = encodedKs.replace("\r", "");
		
		return encodedKs;
		} catch (GeneralSecurityException ex) {
			logger.error("Failed to generate v2 session.");
			throw new Exception(ex);
		} 
	}
	
	private byte[] createRandomByteArray(int size)	{
		byte[] b = new byte[size];
		new Random().nextBytes(b);
		return b;
	}

}
